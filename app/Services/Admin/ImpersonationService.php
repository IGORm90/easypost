<?php

namespace App\Services\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Owns the session lifecycle and logging for
 * POST /api/admin/impersonate/{user} and /stop. `impersonator_id`, written
 * here and nowhere else, is the single source of truth for "is this
 * session impersonating, and who is the real actor" — DenyWhileImpersonating
 * (blocks sensitive actions) and this class's own nested-start guard both
 * read it, so there is exactly one place that can make a session
 * "impersonating".
 */
class ImpersonationService
{
    /**
     * Starts impersonation. The `superadmin` route middleware has already
     * confirmed $actor->is_superadmin before this runs — this method's own
     * job is the two checks that middleware *can't* express (self-target,
     * nested impersonation) plus the actual session/log mechanics.
     */
    public function start(Request $request, User $actor, User $target): User
    {
        if ($request->session()->has('impersonator_id')) {
            // An impersonated session reaching here means the *target*
            // user also happens to be a superadmin (the only way past the
            // `superadmin` middleware) — the middleware alone cannot see
            // that this session is itself already impersonating, only
            // this service can, since only it holds the session-recorded
            // real actor.
            abort(403, 'Cannot start a new impersonation session from within one.');
        }

        if ($actor->is($target)) {
            abort(422, 'Cannot impersonate yourself.');
        }

        Log::info('impersonation.started', [
            'actor_id' => $actor->id,
            'target_id' => $target->id,
        ]);

        $request->session()->put('impersonator_id', $actor->id);
        // Explicit 'web' guard, not the bare Auth::login() facade call:
        // these routes sit behind `auth:sanctum`, and Illuminate\Auth\
        // Middleware\Authenticate::authenticate() calls
        // $auth->shouldUse('sanctum') as a side effect of authenticating
        // the request, making 'sanctum' — a stateless RequestGuard with
        // no login() method at all — the default guard for the rest of
        // this request. AuthService::login() can call the bare facade
        // form safely only because /api/auth/login sits in front of
        // auth:sanctum and never triggers that side effect.
        Auth::guard('web')->login($target);
        // false: migrate the session's data (impersonator_id included) to
        // the new id rather than discarding it — the fixation-defeating
        // behaviour AuthService::login() uses, applied here too so a
        // pre-impersonation session id is never reused post-impersonation.
        $request->session()->regenerate(false);

        return $target;
    }

    /**
     * Ends impersonation and restores the real actor. Deliberately
     * authorizes itself off the session-recorded `impersonator_id` alone,
     * never off $request->user()->is_superadmin — the currently
     * authenticated identity while impersonating is the *target*, who may
     * not be a superadmin at all, and stop() must still work for them.
     *
     * A session with no recorded impersonator is a no-op from the caller's
     * point of view: it is rejected with 409, and specifically never
     * treated as "start impersonating nobody in particular" or any other
     * path that could leave the caller better off than before calling it.
     */
    public function stop(Request $request): User
    {
        $impersonatorId = $request->session()->get('impersonator_id');

        if (! is_string($impersonatorId)) {
            abort(409, 'Not currently impersonating.');
        }

        $target = $request->user();
        $realActor = User::find($impersonatorId);

        if (! $realActor instanceof User) {
            // The real actor's account no longer exists (deleted mid
            // impersonation). Fail closed: end the session entirely
            // rather than leaving the caller signed in as the target with
            // no way back.
            $request->session()->invalidate();

            abort(403, 'The impersonating account no longer exists.');
        }

        Log::info('impersonation.stopped', [
            'actor_id' => $realActor->id,
            'target_id' => $target?->id,
        ]);

        $request->session()->forget('impersonator_id');
        Auth::guard('web')->login($realActor);
        $request->session()->regenerate(false);

        return $realActor;
    }
}
