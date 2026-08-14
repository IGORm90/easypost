<?php

use App\Enums\PostState;
use App\Jobs\PublishPostJob;
use App\Models\Integration;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Billing\PlanLimitService;
use App\Services\Posts\PublishService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Task 37: one test that walks the whole MVP surface end to end —
 * register, connect a channel, upload media, schedule a post, see it on
 * the calendar, publish it, and confirm plan usage reflects it. This is
 * deliberately a thin proof that every layer wires together, not a
 * substitute for the dedicated suites (CreatePostTest, UploadMediaTest,
 * PublishPostJobTest, CalendarTest, PlanLimitServiceTest, ...) that
 * already cover each step's edge cases in depth.
 *
 * Response-shape notes (this test asserts against the REAL response
 * shapes, verified against the controllers, not the task-37 brief's
 * original plan-era sketch):
 *  - POST /api/posts returns the PostGroupResource body directly, with
 *    NO "data" wrapper: PostController::store() calls
 *    `response()->json(new PostGroupResource($group), 201)`, and
 *    JsonResource::$wrap is only ever applied via ->response()/
 *    toResponse() (see MediaController's docblock for the same rule
 *    spelled out) — so the id is at `id`, not `data.id`.
 *  - POST /api/media DOES wrap under "data": MediaController::store()
 *    explicitly calls `(new MediaResource($media))->response()`.
 *  - GET /api/calendar returns a bare JSON array (no wrapper either),
 *    same reasoning as posts — see CalendarTest for the established
 *    pattern of asserting against the top-level array directly.
 *  - PlanLimitService::usage()['channels'] is
 *    `{current: int, max: int|null}`, not a bare int — see that
 *    class's docblock.
 *
 * Auth-flow note: POST /api/auth/register does not log the caller in
 * (see AuthController::register — no AuthService::login() call, unlike
 * login()); a real client always follows registration with an explicit
 * login. This test does the same, rather than reaching for actingAs(),
 * to prove the actual documented "register then log in" flow works.
 */
it('covers the full mvp flow end to end', function () {
    Storage::fake('media');
    Queue::fake();

    // 1. Registration.
    $this->postJson('/api/auth/register', [
        'name' => 'Ann',
        'email' => 'ann@e.com',
        'password' => 'secret1234',
    ])->assertCreated();

    // Registration does not start a session (see this file's docblock) —
    // log in for real, exactly as the frontend does immediately after
    // registering. The Referer header is what makes Sanctum's
    // EnsureFrontendRequestsAreStateful recognise this as a first-party
    // SPA request and actually start a session — see LoginTest, which
    // establishes this exact pattern; without it every call here would
    // hit "Session store not set on request."
    $this->withHeader('Referer', config('app.url'));

    $this->postJson('/api/auth/login', [
        'email' => 'ann@e.com',
        'password' => 'secret1234',
    ])->assertOk();

    $org = User::firstWhere('email', 'ann@e.com')->organizations->first();
    expect($org)->not->toBeNull();

    $this->withHeader('X-Organization', $org->id);

    // 2. Connect a channel. A real OAuth round-trip is out of scope for
    // an automated test (see docs/ACCEPTANCE.md) — the fixture is a
    // fully-formed Integration row, exactly like every other feature
    // test in this suite (CreatePostTest, CalendarTest, ...) does.
    $integration = Integration::factory()->for($org)->create(['provider_identifier' => 'fake']);

    // 3. Upload media.
    $mediaId = $this->postJson('/api/media', ['file' => UploadedFile::fake()->image('a.jpg')])
        ->assertCreated()
        ->json('data.id');

    // 4. Create a scheduled post using the uploaded media.
    $groupId = $this->postJson('/api/posts', [
        'type' => 'schedule',
        'publish_date' => now()->addHour()->toIso8601String(),
        'posts' => [
            [
                'integration_id' => $integration->id,
                'value' => [['content' => 'hello', 'media_ids' => [$mediaId]]],
            ],
        ],
    ])->assertCreated()->json('id');

    // 5. The publish job was queued for this exact group.
    Queue::assertPushed(PublishPostJob::class, fn (PublishPostJob $job): bool => $job->postGroupId === $groupId);

    // 6. The post is visible on the calendar.
    $calendar = $this->getJson('/api/calendar?'.http_build_query([
        'from' => now()->toDateString(),
        'to' => now()->addWeek()->toDateString(),
    ]))->assertOk()->json();

    expect($calendar)->toHaveCount(1)
        ->and($calendar[0]['id'])->toBe($groupId);

    // 7. Publish it for real (Queue::fake() above means PublishPostJob
    // was never actually run, so nothing has published yet). Travel past
    // the scheduled time first: PublishService::lockGroupIfQueued()
    // refuses to publish a group whose publish_date is still materially
    // in the future (see that method's docblock) — a real worker would
    // only ever pick this job up once its delay has elapsed.
    $this->travelTo(now()->addHours(2));
    app(PublishService::class)->publishGroup($groupId);

    expect(PostGroup::find($groupId)->state)->toBe(PostState::PUBLISHED);

    // 8. Plan usage reflects the one connected channel.
    expect(app(PlanLimitService::class)->usage($org)['channels']['current'])->toBe(1);
});
