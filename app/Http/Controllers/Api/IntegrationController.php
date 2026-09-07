<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConnectIntegrationRequest;
use App\Http\Requests\ProviderOptionsRequest;
use App\Http\Requests\UpdateIntegrationRequest;
use App\Http\Resources\IntegrationResource;
use App\Models\Integration;
use App\Models\Organization;
use App\Models\User;
use App\Services\Integrations\Exceptions\InvalidOAuthStateException;
use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\OAuthStateService;
use App\Social\Contracts\DeclaresAuthFields;
use App\Social\Contracts\DeclaresPostSettings;
use App\Social\Contracts\DeclaresUpdatableSettings;
use App\Social\Contracts\ManualTokenAuth;
use App\Social\Contracts\SocialProviderInterface;
use App\Social\Contracts\SupportsOptions;
use App\Social\Exceptions\ProviderAuthException;
use App\Social\Exceptions\ProviderPermanentException;
use App\Social\Exceptions\ProviderRateLimitException;
use App\Social\Exceptions\UnknownProviderException;
use App\Social\Exceptions\UnknownProviderOptionException;
use App\Social\ProviderRegistry;
use App\Support\OrganizationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * HTTP surface for connecting and managing channels (Task 13). Pure
 * orchestration: every decision that isn't "which HTTP status/shape" is
 * delegated — provider resolution to ProviderRegistry, state CSRF
 * lifecycle to OAuthStateService, all writes to IntegrationService,
 * authorization to IntegrationPolicy via Gate::authorize(). No
 * provider-specific branching anywhere in this class (see
 * tests/Unit/Architecture/ProviderIsolationTest.php).
 *
 * `{id}`-scoped actions (update/disable/enable/destroy/options) resolve
 * their Integration via `Integration::query()->findOrFail($id)` — never
 * `withoutGlobalScopes()` — so the lookup itself is confined to the
 * current organization by BelongsToOrganization's global scope (active
 * because the `organization` route middleware runs before this
 * controller). A wrong-organization id therefore 404s exactly like a
 * nonexistent one: this endpoint is not an existence oracle for other
 * organizations' channels.
 */
class IntegrationController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly IntegrationService $integrations,
        private readonly OAuthStateService $states,
    ) {}

    public function providers(): JsonResponse
    {
        $payload = array_map(
            fn (SocialProviderInterface $provider): array => [
                'identifier' => $provider->identifier(),
                'name' => $provider->name(),
                'capabilities' => $provider->capabilities(),
                'auth_type' => $provider instanceof ManualTokenAuth ? 'manual' : 'oauth',
                'auth_fields' => $provider instanceof DeclaresAuthFields ? $provider->authFields() : [],
                'settings_fields' => $this->settingsFields($provider),
                'post_settings' => $this->postSettings($provider),
            ],
            array_values($this->providers->all()),
        );

        return response()->json($payload);
    }

    /**
     * The settings a user picks from a server-provided list, as
     * `{key, option}` pairs — the capability signal the settings drawer
     * renders its pickers from (final review, Critical 4).
     *
     * Both halves have to be declared for a picker to exist: the key must
     * be updatable at all (`DeclaresUpdatableSettings`, which is what the
     * PUT validates against) AND it must name an option list to pick from
     * (`SupportsOptions::settingOptions()`). A key declared updatable with
     * no list is simply not a picker — it is typed, and is not listed
     * here.
     *
     * This is the whole reason the frontend needs no provider names: it
     * asks what a channel can do, never who the channel is with.
     *
     * @return list<array{key: string, option: string}>
     */
    private function settingsFields(SocialProviderInterface $provider): array
    {
        if (! $provider instanceof SupportsOptions || ! $provider instanceof DeclaresUpdatableSettings) {
            return [];
        }

        $updatable = $provider->updatableSettings();

        $fields = [];

        foreach ($provider->settingOptions() as $key => $option) {
            if (array_key_exists($key, $updatable)) {
                $fields[] = ['key' => $key, 'option' => $option];
            }
        }

        return $fields;
    }

    /**
     * The per-post fields a provider declares (Block 5.0.6), stripped of
     * their validation rules.
     *
     * `rules` is the one key deliberately withheld: it is a list of
     * Laravel rule strings, and publishing it invites the editor to
     * enforce them client-side — at which point there are two validators
     * for one contract, and they disagree the first time a provider
     * changes a rule. The editor renders the field and marks it required;
     * whether a value is acceptable is decided in exactly one place,
     * PostValidationService, which is also the place that already decides
     * it identically at scheduling time and at publish time.
     *
     * @return list<array{key: string, type: string, required: bool, option?: string, dependsOn?: string}>
     */
    private function postSettings(SocialProviderInterface $provider): array
    {
        if (! $provider instanceof DeclaresPostSettings) {
            return [];
        }

        return array_map(
            function (array $field): array {
                unset($field['rules']);

                return $field;
            },
            $provider->postSettings(),
        );
    }

    /**
     * Eager-loads `timeSlots` so `IntegrationResource::time_slots` is
     * actually populated here (its `whenLoaded()` would otherwise omit
     * the key entirely) — the channels page (Task 17) has no other way
     * to learn a channel's existing weekly schedule before opening its
     * settings editor, since there is no single-integration `show`
     * route.
     */
    public function index(): JsonResponse
    {
        return response()->json(IntegrationResource::collection(Integration::query()->with('timeSlots')->get()));
    }

    /**
     * Mints a CSRF `state` bound to the current organization+user (see
     * OAuthStateService) and asks the provider where to send the user's
     * browser. `role:ADMIN` (route middleware) gates this, not a check
     * here — starting a connect flow carries the same authorization bar
     * as completing one (IntegrationPolicy::manage()).
     *
     * Everything the caller sent beyond the implicit state/redirect
     * (query string or JSON body -- `$request->all()` merges both) is
     * forwarded verbatim to `generateAuthUrl()`'s `$params` (Task 14):
     * e.g. a federated network needs the user-chosen instance host
     * before it can build an authorize URL at all. This controller has no idea what
     * shape that data takes for any given provider (same rule as
     * `callback()` passing `$request->except('state')` through to
     * `authenticate()` below) -- it only knows how to hand the request
     * through generically.
     */
    public function authUrl(Request $request, string $provider): JsonResponse
    {
        $providerInstance = $this->resolveProvider($provider);
        $state = $this->states->generate($this->currentOrganization(), $this->currentUser());
        $authUrl = $providerInstance->generateAuthUrl($state, $this->redirectUri(), $request->all());

        // Block 5.0.2: `extra` is now remembered SERVER SIDE, against the
        // state it belongs to. It used to travel to the SPA and come back
        // in the callback body, which was fine while its only content
        // was an instance host — but the networks added in Block 5 put a
        // PKCE `code_verifier` there, and a secret the browser
        // holds is a secret the browser can also replace. `callback()`
        // now layers this copy over the request body, so the stored value
        // is the one that reaches the provider.
        $this->states->remember($state, $authUrl->extra);

        // Still returned to the SPA, unchanged: `ConnectProviderModal`
        // persists it and `Callback.vue` posts it back, and both keep
        // working exactly as before — those keys are simply no longer
        // trusted. Removing it from the response would break any tab that
        // started a connect before this deploy, for no gain.
        return response()->json(['url' => $authUrl->url, 'state' => $state, 'extra' => $authUrl->extra]);
    }

    /**
     * Completes a connect flow. Deliberately does not require the
     * `organization` route middleware/X-Organization header — the
     * organization is the one bound to `state` (OAuthStateService is the
     * sole source of truth for it; see its docblock), not anything this
     * request could otherwise supply.
     */
    public function callback(ConnectIntegrationRequest $request, string $provider): JsonResponse
    {
        $providerInstance = $this->resolveProvider($provider);

        try {
            $state = $this->states->consume((string) $request->validated('state'), $this->currentUser());
        } catch (InvalidOAuthStateException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $organization = $state->organization;

        // Block 5.0.2: the server's own copy of what the provider asked
        // to remember is applied ON TOP of the request body, so a browser
        // cannot substitute a `code_verifier`, an instance host or a
        // page id for the one this app issued. `state` is stripped
        // afterwards because the stored extra may legitimately contain it
        // (FakeProvider returns exactly that) and `authenticate()`'s
        // contract has never included it.
        $params = [...$request->except('state'), ...$state->extra];
        unset($params['state']);

        try {
            $auth = $providerInstance->authenticate($params, $this->redirectUri());
        } catch (ProviderAuthException|ProviderPermanentException) {
            // Both mean "this attempt cannot succeed as submitted" — a
            // rejected credential (bad Telegram bot token/chat id, a
            // revoked Bluesky app password, a rejected OAuth code
            // exchange, ...) or a malformed/permanently-invalid
            // request the provider will never accept by retrying. Task
            // 17 review, Important finding: previously uncaught here,
            // so the single most likely user error in the connect flow
            // (a mistyped manual-token credential) surfaced as a raw
            // 500 — indistinguishable in monitoring from an actual
            // application bug, and the frontend had no way to show a
            // "check your input" message instead of a generic failure.
            // The message is a fixed, generic string — never
            // $e->getMessage() — because provider exception messages
            // are built for logs (MapsHttpErrors/authErrorFor()
            // interpolate the provider's own response body) and must
            // not be echoed back to the client.
            return response()->json(['message' => 'This channel could not be connected. Check the details and try again.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ProviderRateLimitException) {
            // The provider itself asked us to back off — not our
            // server's fault (so not a 5xx) and not the user's bad
            // input either (so not a 422); 429 is the accurate status
            // for both a human reading the response and any client-side
            // retry logic that inspects it.
            return response()->json(['message' => 'The provider is rate-limiting this request. Please try again shortly.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $integration = $this->integrations->connect($organization, $providerInstance->identifier(), $auth);

        // fresh('timeSlots'), not fresh(): IntegrationResource::time_slots
        // is whenLoaded(), so an unloaded relation drops the key from the
        // payload entirely instead of returning it empty. The SPA store
        // splices this object over the one it holds, and the settings
        // drawer then seeds its slot grid from a missing key — arming the
        // next Save to PUT an empty time_slots and delete every slot the
        // channel had. Reconnecting a channel must never do that.
        return response()->json(new IntegrationResource($integration->fresh('timeSlots') ?? $integration), Response::HTTP_CREATED);
    }

    public function update(UpdateIntegrationRequest $request, string $id): JsonResponse
    {
        $integration = $this->findAuthorized($id, 'manage');
        $integration = $this->integrations->update($integration, $request->validated());

        return response()->json(new IntegrationResource($integration));
    }

    public function disable(string $id): JsonResponse
    {
        $integration = $this->findAuthorized($id, 'manage');
        $this->integrations->disable($integration);

        // fresh('timeSlots') — see connect()'s note above: a bare fresh()
        // omits the key and silently arms a schedule wipe.
        return response()->json(new IntegrationResource($integration->fresh('timeSlots') ?? $integration));
    }

    public function enable(string $id): JsonResponse
    {
        $integration = $this->findAuthorized($id, 'manage');
        $this->integrations->enable($integration);

        // fresh('timeSlots') — see connect()'s note above.
        return response()->json(new IntegrationResource($integration->fresh('timeSlots') ?? $integration));
    }

    public function destroy(string $id): Response
    {
        $integration = $this->findAuthorized($id, 'manage');
        $this->integrations->softDelete($integration);

        return response()->noContent();
    }

    /**
     * Generic delegate to the provider — see SupportsOptions. This
     * method knows nothing about what any given `$option` means for any
     * given provider; it only knows how to say "not supported" (404)
     * when the provider doesn't implement the capability, or doesn't
     * recognise the option name.
     */
    public function options(ProviderOptionsRequest $request, string $id, string $option): JsonResponse
    {
        $integration = $this->findAuthorized($id, 'view');
        $providerInstance = $this->resolveProvider($integration->provider_identifier);

        if (! $providerInstance instanceof SupportsOptions) {
            throw new NotFoundHttpException;
        }

        try {
            // Block 5.0.6: `context` is what makes a dependent list
            // answerable (a flair list exists per subreddit, not per
            // account). Passed through verbatim and un-interpreted — this
            // controller does not know what any key means, which is the
            // point; the request class guarantees only its shape.
            $options = $providerInstance->options($integration, $option, $request->context());
        } catch (UnknownProviderOptionException) {
            throw new NotFoundHttpException;
        }

        return response()->json(['options' => $options]);
    }

    /**
     * $identifier is never trusted as a UnknownProviderException-free
     * value — every caller goes through here first, so an unregistered
     * provider 404s uniformly instead of one call site leaking the
     * registry's own exception (and its class name) to the client.
     */
    private function resolveProvider(string $identifier): SocialProviderInterface
    {
        try {
            return $this->providers->get($identifier);
        } catch (UnknownProviderException) {
            throw new NotFoundHttpException('Unknown provider.');
        }
    }

    private function findAuthorized(string $id, string $ability): Integration
    {
        $integration = Integration::query()->findOrFail($id);
        Gate::authorize($ability, $integration);

        return $integration;
    }

    private function currentOrganization(): Organization
    {
        /** @var Organization $organization */
        $organization = OrganizationContext::get();

        return $organization;
    }

    private function currentUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function redirectUri(): string
    {
        return (string) config('services.integrations_redirect_url');
    }
}
