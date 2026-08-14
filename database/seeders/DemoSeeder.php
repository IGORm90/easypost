<?php

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Enums\Plan;
use App\Enums\PostState;
use App\Models\Integration;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Post;
use App\Models\PostGroup;
use App\Models\Tag;
use App\Models\User;
use App\Services\Media\MediaUploadService;
use App\Support\OrganizationContext;
use DateTimeInterface;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Task 37: `php artisan db:seed --class=DemoSeeder` builds a walkable
 * demo workspace — one organization, three connected channels, five
 * real media files, and ten post groups spanning every lifecycle state
 * a human reviewing the acceptance checklist (docs/ACCEPTANCE.md) needs
 * to see rendered: draft, scheduled, published (single-channel,
 * multi-channel and threaded), and failed.
 *
 * Idempotent by construction, not by accident: the demo user/org are
 * looked up by fixed, well-known identifiers (DEMO_EMAIL/DEMO_SLUG) via
 * `updateOrCreate()`, and every other piece of demo content under that
 * organization is deleted and rebuilt from scratch on every run —
 * `wipeExistingContent()` below, including the real objects this run's
 * predecessor wrote to the `media` disk (MinIO in dev; see
 * MediaUploadService's docblock for why that disk is never the
 * application's own filesystem). Running this command twice in a row
 * therefore converges to the exact same ten post groups and five media
 * rows, never a growing pile of duplicates — "runnable repeatedly on a
 * dev database without corrupting it" per the task-37 brief. The
 * organization's own id is stable across runs too (same row, reused),
 * which is what lets docs/ACCEPTANCE.md and docs/DEPLOYMENT.md print a
 * single `php artisan org:plan {id} free` example that keeps working.
 *
 * Guarded against the one database this must never touch: the test
 * suite's `app_test` database (RefreshDatabase truncates between every
 * test — a demo seed run wiping that out from under a concurrent test
 * run would be exactly the "corrupting a database" failure mode this
 * class otherwise goes out of its way to avoid). `php artisan test`
 * forces `APP_ENV=testing` (see phpunit.xml), which is what this guard
 * checks — not the database name alone, since a misconfigured `.env`
 * could still point `DB_DATABASE` at `app_test` outside the test
 * runner.
 *
 * Provider identifiers used here (telegram/mastodon/bluesky) are real,
 * already-shipped providers (see config/social.php) — deliberately not
 * the test-only 'fake' identifier (`Tests\TestCase::setUp()` wires that
 * into `config('social.providers')` only inside the test process; see
 * that class's docblock), so a demo Integration renders with its real
 * icon/preview in the UI exactly like a genuinely connected channel
 * would. Nothing here ever calls `ProviderRegistry::get()` or reaches
 * the network — ProviderIsolationTest's grep only covers `app/`, not
 * `database/`, but this class still never branches on a provider name
 * for any behavioural reason, only to label fixtures for a human to
 * look at.
 *
 * Every token value below is an obvious, fixed placeholder string, never
 * logged anywhere in this class (only ids/counts go to the console) —
 * see Integration's docblock for why a raw token must never reach a log
 * line, encrypted-at-rest or not.
 */
class DemoSeeder extends Seeder
{
    private const string DEMO_EMAIL = 'demo@easypost.test';

    private const string DEMO_PASSWORD = 'demo12345';

    private const string DEMO_SLUG = 'demo-workspace';

    private const string PLACEHOLDER_TOKEN = 'demo-fixture-token-not-a-real-secret';

    public function run(): void
    {
        if (app()->environment('testing')) {
            throw new RuntimeException(
                'DemoSeeder must never run against the test environment/database.',
            );
        }

        $user = User::query()->updateOrCreate(
            ['email' => self::DEMO_EMAIL],
            [
                'name' => 'Demo Owner',
                'password' => Hash::make(self::DEMO_PASSWORD),
                'email_verified_at' => now(),
            ],
        );

        $organization = Organization::query()->updateOrCreate(
            ['slug' => self::DEMO_SLUG],
            [
                'name' => 'Demo Workspace',
                'owner_id' => $user->id,
                'plan' => Plan::STANDARD,
            ],
        );

        if ($organization->roleOf($user) === null) {
            $organization->addMember($user, OrganizationRole::SUPERADMIN);
        }

        OrganizationContext::set($organization);

        try {
            $this->wipeExistingContent($organization);

            $integrations = $this->createIntegrations($organization);
            $media = $this->createMedia($organization, $user);
            $tag = Tag::create([
                'organization_id' => $organization->id,
                'name' => 'launch',
                'color' => '#6d5efc',
            ]);

            $this->createPostGroups($organization, $user, $integrations, $media, $tag);
        } finally {
            OrganizationContext::clear();
        }

        $this->command->info("Demo workspace ready. Organization id: {$organization->id}");
        $this->command->info('Login: '.self::DEMO_EMAIL.' / '.self::DEMO_PASSWORD);
    }

    /**
     * Deletes every piece of demo content owned by $organization —
     * media (row AND the real object on the `media` disk), post groups
     * (cascades to their posts and post_group_tag rows — see the
     * `post_groups`/`posts` migrations), integrations (soft-deleting;
     * force-deleted here so a reseed never accumulates trashed rows a
     * unique constraint could someday collide with), and tags. The
     * organization and user rows themselves are reused, not deleted —
     * see class docblock for why a stable id matters here.
     *
     * Integration rows are force-deleted one at a time via a scoped
     * `get()->each()`, deliberately NOT `Integration::query()->forceDelete()`:
     * `Illuminate\Database\Eloquent\Builder::forceDelete()` calls
     * `$this->query->delete()` directly on the UNDERLYING base query
     * builder, which skips `applyScopes()` entirely — global scopes
     * (this app's own `organization` scope included) never make it onto
     * that DELETE at all. Verified the hard way: an earlier revision of
     * this method used the bulk form and, on a real dev database that
     * already had unrelated integrations from manual testing, issued a
     * bare `DELETE FROM integrations` with no `WHERE` clause whatsoever
     * — caught only because Postgres happened to reject it on an
     * unrelated foreign key, not because the query was actually scoped.
     * `get()` (used by `PostGroup`/`Media`/`Tag`'s plain `delete()`
     * calls below too) applies scopes normally; only the bulk
     * `forceDelete()` shortcut has this trap.
     */
    private function wipeExistingContent(Organization $organization): void
    {
        Storage::disk('media')->deleteDirectory("org/{$organization->id}");

        Media::query()->delete();
        PostGroup::query()->delete();
        Integration::query()->get()->each(fn (Integration $integration) => $integration->forceDelete());
        Tag::query()->delete();
    }

    /**
     * @return array<string, Integration>
     */
    private function createIntegrations(Organization $organization): array
    {
        $specs = [
            'telegram' => 'Demo Announcements',
            'mastodon' => 'demo@mastodon.social',
            'bluesky' => 'demo.bsky.social',
        ];

        $integrations = [];

        foreach ($specs as $identifier => $name) {
            $integrations[$identifier] = Integration::create([
                'organization_id' => $organization->id,
                'provider_identifier' => $identifier,
                'internal_id' => "demo-{$identifier}-".Str::random(8),
                'name' => $name,
                'picture' => null,
                'profile' => null,
                'token' => self::PLACEHOLDER_TOKEN,
                'refresh_token' => self::PLACEHOLDER_TOKEN.'-refresh',
                'token_expires_at' => null,
                'settings' => [],
                'customer_name' => null,
                'group_name' => null,
                'disabled' => false,
                'in_between_steps' => false,
                'refresh_needed' => false,
            ]);
        }

        return $integrations;
    }

    /**
     * Uploads five real images through `MediaUploadService::store()` —
     * the same code path `POST /api/media` uses — so this seed run
     * proves the real disk write path (MinIO in dev), not merely a
     * `Storage::fake()` simulation of it. See this class's docblock for
     * why that distinction matters.
     *
     * @return list<Media>
     */
    private function createMedia(Organization $organization, User $user): array
    {
        /** @var MediaUploadService $uploads */
        $uploads = app(MediaUploadService::class);

        $filenames = ['launch.jpg', 'product-shot.jpg', 'behind-the-scenes.jpg', 'team.jpg', 'thank-you.jpg'];

        return array_map(
            fn (string $filename): Media => $uploads->store(
                UploadedFile::fake()->image($filename, 1200, 800),
                $organization,
                $user->id,
            ),
            $filenames,
        );
    }

    /**
     * @param  array<string, Integration>  $integrations
     * @param  list<Media>  $media
     */
    private function createPostGroups(
        Organization $organization,
        User $user,
        array $integrations,
        array $media,
        Tag $tag,
    ): void {
        // 1. Draft, single channel, no media — the simplest possible state.
        $this->makeGroup($organization, $user, PostState::DRAFT, now(), [
            $this->channel($integrations['telegram'], ['Draft: outline for next week\'s announcement.']),
        ]);

        // 2. Draft, single channel, with media and a tag.
        $this->makeGroup($organization, $user, PostState::DRAFT, now(), [
            $this->channel($integrations['mastodon'], ['Draft: launch teaser, still picking the photo.'], [[$media[0]]]),
        ], tag: $tag);

        // 3. Scheduled, single channel, tomorrow.
        $this->makeGroup($organization, $user, PostState::QUEUE, now()->addDay(), [
            $this->channel($integrations['telegram'], ["We're live tomorrow! \u{1F680}"]),
        ]);

        // 4. Scheduled, two channels at once, with media on one of them.
        $this->makeGroup($organization, $user, PostState::QUEUE, now()->addDays(2), [
            $this->channel($integrations['bluesky'], ['New product shots are up.'], [[$media[1]]]),
            $this->channel($integrations['mastodon'], ['New product shots are up.'], [[$media[1]]]),
        ], tag: $tag);

        // 5. Scheduled thread (three linked segments) on one channel.
        $this->makeGroup($organization, $user, PostState::QUEUE, now()->addDays(3), [
            $this->channel($integrations['telegram'], [
                'Thread: why we built this (1/3)',
                'Thread: what changed for you (2/3)',
                'Thread: what\'s next (3/3)',
            ]),
        ]);

        // 6. Published, single channel, with media, a few days ago.
        $this->makeGroup($organization, $user, PostState::PUBLISHED, now()->subDays(4), [
            $this->channel($integrations['telegram'], ['We shipped the new scheduler today.'], [[$media[2]]], released: true),
        ]);

        // 7. Published, two channels at once.
        $this->makeGroup($organization, $user, PostState::PUBLISHED, now()->subDays(5), [
            $this->channel($integrations['bluesky'], ['Meet the team behind the launch.'], [[$media[3]]], released: true),
            $this->channel($integrations['mastodon'], ['Meet the team behind the launch.'], [[$media[3]]], released: true),
        ]);

        // 8. Published thread (two linked segments), a week ago.
        $this->makeGroup($organization, $user, PostState::PUBLISHED, now()->subWeek(), [
            $this->channel($integrations['telegram'], [
                'Thank you to everyone who joined the beta (1/2)',
                'Full changelog is in the docs (2/2)',
            ], released: true),
        ]);

        // 9. Failed publish, single channel — e.g. an expired token.
        $this->makeGroup(
            $organization,
            $user,
            PostState::ERROR,
            now()->subDay(),
            [$this->channel($integrations['mastodon'], ['This one failed to send.'])],
            error: 'The connected channel rejected the request: token expired.',
        );

        // 10. Failed publish across both channels it targeted.
        $this->makeGroup(
            $organization,
            $user,
            PostState::ERROR,
            now()->subDays(2),
            [
                $this->channel($integrations['telegram'], ['Both channels failed on this one.']),
                $this->channel($integrations['bluesky'], ['Both channels failed on this one.']),
            ],
            error: 'The connected channel rejected the request: rate limited.',
        );
    }

    /**
     * Builds one channel's chain spec for makeGroup() — one array entry
     * per linked segment (a thread when there's more than one).
     *
     * @param  list<string>  $contents
     * @param  list<list<Media>>|null  $mediaPerSegment  Same length as $contents when given; media attached to the segment at the same index.
     * @return array{integration: Integration, contents: list<string>, media: list<list<Media>>, released: bool}
     */
    private function channel(Integration $integration, array $contents, ?array $mediaPerSegment = null, bool $released = false): array
    {
        return [
            'integration' => $integration,
            'contents' => $contents,
            'media' => $mediaPerSegment ?? array_fill(0, count($contents), []),
            'released' => $released,
        ];
    }

    /**
     * Creates one PostGroup and every Post segment in every channel spec
     * given, wiring `parent_post_id`/`order` for threads exactly like
     * `PostGroupService::replaceChains()` does. `released` on a channel
     * spec stamps `release_id`/`release_url`/`published_at` directly —
     * this seeder never dispatches `PublishPostJob` or calls
     * `PublishService`, so a "published" demo row is data crafted to
     * look like the real outcome, not a row that actually went through
     * the publish pipeline (a real network call is out of scope for a
     * seeder — see docs/ACCEPTANCE.md for the manual publish checks).
     *
     * @param  list<array{integration: Integration, contents: list<string>, media: list<list<Media>>, released: bool}>  $channels
     */
    private function makeGroup(
        Organization $organization,
        User $user,
        PostState $state,
        DateTimeInterface $publishDate,
        array $channels,
        ?Tag $tag = null,
        ?string $error = null,
    ): PostGroup {
        $group = PostGroup::create([
            'organization_id' => $organization->id,
            'state' => $state,
            'publish_date' => $publishDate,
            'created_by' => $user->id,
            'error' => $error,
            'notified_at' => null,
            'share_token' => null,
            'queue_version' => $state === PostState::QUEUE ? 1 : 0,
            'last_dispatched_at' => null,
        ]);

        foreach ($channels as $channel) {
            $parentId = null;

            foreach ($channel['contents'] as $index => $content) {
                $released = $channel['released'];

                $post = Post::create([
                    'post_group_id' => $group->id,
                    'organization_id' => $organization->id,
                    'integration_id' => $channel['integration']->id,
                    'parent_post_id' => $parentId,
                    'order' => $index,
                    'content' => $content,
                    'settings' => [],
                    'state' => $state,
                    'release_id' => $released ? 'demo-release-'.Str::random(10) : null,
                    'release_url' => $released ? 'https://example.com/demo/'.Str::random(10) : null,
                    'error' => $state === PostState::ERROR ? $error : null,
                    'published_at' => $released ? $publishDate : null,
                ]);

                $mediaForSegment = $channel['media'][$index] ?? [];

                if ($mediaForSegment !== []) {
                    $pivotRows = [];
                    foreach ($mediaForSegment as $order => $mediaItem) {
                        $pivotRows[$mediaItem->id] = ['order' => $order];
                    }
                    $post->media()->attach($pivotRows);
                }

                $parentId = $post->id;
            }
        }

        if ($tag !== null) {
            $group->tags()->attach($tag);
        }

        return $group;
    }
}
