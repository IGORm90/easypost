<?php

use App\Enums\OrganizationRole;
use App\Enums\PostState;
use App\Jobs\SendDigestEmailJob;
use App\Mail\WeeklyDigestMail;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Mail\DigestService;
use App\Support\OrganizationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Task 33: the weekly digest — see DigestService's class docblock for the
 * full "Monday 09:00 in the user's timezone", cross-tenant, and
 * idempotency reasoning this suite exercises directly.
 *
 * Runs with NO OrganizationContext set throughout, matching production
 * (a scheduled job — see AppServiceProvider's queue-event listeners):
 * every fixture below is built with an explicit organization_id/pivot,
 * never relying on the request-scoped context DigestService never sets.
 */

beforeEach(function () {
    OrganizationContext::clear();
    Carbon::setTestNow();
});

afterEach(function () {
    OrganizationContext::clear();
    Carbon::setTestNow();
});

function memberOf(Organization $org, User $user, OrganizationRole $role = OrganizationRole::ADMIN): void
{
    $org->addMember($user, $role);
}

/**
 * `Illuminate\Support\Testing\Fakes\MailFake` has no `assertSentTo()` —
 * that shortcut only exists on the Notification fake (see
 * `PostFailedNotification`'s tests). This wraps the same check via
 * `Mail::assertSent()`'s closure form, `Mailable::hasTo()` matching the
 * envelope's actual `to` address.
 */
function assertDigestSentTo(User $user, ?Closure $extra = null): void
{
    Mail::assertSent(WeeklyDigestMail::class, function (WeeklyDigestMail $mail) use ($user, $extra): bool {
        return $mail->hasTo($user->email) && ($extra === null || $extra($mail));
    });
}

function assertDigestNotSentTo(User $user): void
{
    Mail::assertNotSent(WeeklyDigestMail::class, fn (WeeklyDigestMail $mail): bool => $mail->hasTo($user->email));
}

function digestSentCountTo(User $user): int
{
    return Mail::sent(WeeklyDigestMail::class)
        ->filter(fn (WeeklyDigestMail $mail): bool => $mail->hasTo($user->email))
        ->count();
}

/**
 * Forks one child process per closure, each on its own fresh DB
 * connection — see tests/Pest.php's raceConcurrently() docblock for why
 * a forked child cannot safely reuse the parent's live PDO socket, and
 * why Mail::fake()'s recorded state does not cross the fork boundary
 * back to the parent (each closure must read its own Mail::sent() count
 * from inside its own child, same shape as MissedPostsTest's own local
 * forkAndCollect() helper for Notification::fake() — named
 * digestForkAndCollect() here purely to avoid redeclaring that other
 * file's global function when both test files run in the same suite.
 * Every closure's return value is cast to a string and prefixed 'ok:',
 * written to a results file; a thrown Throwable is captured as
 * 'fail:<message>' instead of crashing the race.
 *
 * @param  array<int, Closure(): (int|string)>  $closures
 * @return array<int, string>
 */
function digestForkAndCollect(array $closures): array
{
    $resultDir = sys_get_temp_dir().'/digest-fork-'.bin2hex(random_bytes(6));
    mkdir($resultDir);

    $pids = [];

    foreach ($closures as $i => $closure) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('pcntl_fork() failed — cannot simulate concurrency.');
        }

        if ($pid === 0) {
            $childConnection = 'race_fork_child_'.$i.'_'.getmypid();
            config(['database.connections.'.$childConnection => config('database.connections.pgsql')]);
            config(['database.default' => $childConnection]);

            try {
                $result = 'ok:'.$closure();
            } catch (Throwable $e) {
                $result = 'fail:'.$e->getMessage();
            }

            file_put_contents("{$resultDir}/{$i}", $result);

            posix_kill(posix_getpid(), SIGKILL);
        }

        $pids[$i] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $results = [];

    foreach (array_keys($closures) as $i) {
        $results[$i] = trim((string) file_get_contents("{$resultDir}/{$i}"));
    }

    foreach (glob("{$resultDir}/*") ?: [] as $file) {
        unlink($file);
    }
    rmdir($resultDir);

    return $results;
}

/**
 * A real Monday, 09:15 UTC — inside DigestService::isDueNow()'s window
 * for a UTC-timezone user.
 */
function mondayNineUtc(): Carbon
{
    return Carbon::parse('2026-08-17 09:15:00', 'UTC');
}

// --- Preference check --------------------------------------------------

it('does not send when the user has disabled email notifications', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => false]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();

    Mail::assertNothingSent();
});

it('sends when the user has email notifications enabled and their local time is due', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();

    assertDigestSentTo($user);
});

// --- Scheduling: only the users whose LOCAL clock is Monday 09:00 ------

it('does not send to a user whose local time is not yet Monday 09:00', function () {
    Mail::fake();
    // 09:15 UTC is only 04:15 in America/New_York (UTC-5) that same day.
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'America/New_York', 'email_notifications' => true]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();

    Mail::assertNothingSent();
});

it('sends to a user whose local time only just became Monday 09:00, in a different zone from a user who is not due', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();

    $dueUser = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    $notDueUser = User::factory()->create(['timezone' => 'America/New_York', 'email_notifications' => true]);
    memberOf($org, $dueUser);
    memberOf($org, $notDueUser);

    app(DigestService::class)->sendDueDigests();

    assertDigestSentTo($dueUser);
    assertDigestNotSentTo($notDueUser);
});

it('correctly resolves Monday 09:00 local across a DST fall-back transition, via real IANA tzdata rather than a fixed offset', function () {
    // 2026-10-25 is the last Sunday of October: Europe/Berlin falls back
    // from CEST (UTC+2) to CET (UTC+1) that night. The FIRST Monday after
    // the transition, 2026-10-26, has its 09:00 local instant at 08:00
    // UTC -- one hour later, in UTC terms, than the previous (summer-time)
    // Monday would have been. This proves isDueNow() reads the real,
    // tzdata-backed offset for "now" rather than a stale/fixed one.
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-26 08:10:00', 'UTC'));

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'Europe/Berlin', 'email_notifications' => true]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();

    assertDigestSentTo($user);

    // The same UTC instant one hour earlier is still 07:10 local in
    // Berlin (pre-transition math would have placed 09:00 local at
    // 07:00 UTC) -- confirms this isn't accidentally matching a wider
    // window than the real one.
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-10-26 07:10:00', 'UTC'));
    app(DigestService::class)->sendDueDigests();
    Mail::assertNothingSent();
});

// --- Cross-organization visibility (the security-critical case) -------

it('includes data from every organization a user belongs to, and never a third organization they do not', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $orgA = Organization::factory()->create(['name' => 'Org A']);
    $orgB = Organization::factory()->create(['name' => 'Org B']);
    $orgC = Organization::factory()->create(['name' => 'Org C - not a member']);

    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($orgA, $user);
    memberOf($orgB, $user);
    // Deliberately NOT a member of $orgC.

    $groupA = PostGroup::factory()->create([
        'organization_id' => $orgA->id,
        'state' => PostState::PUBLISHED,
        'publish_date' => now()->subDays(2),
    ]);
    $groupB = PostGroup::factory()->create([
        'organization_id' => $orgB->id,
        'state' => PostState::PUBLISHED,
        'publish_date' => now()->subDay(),
    ]);
    $groupC = PostGroup::factory()->create([
        'organization_id' => $orgC->id,
        'state' => PostState::PUBLISHED,
        'publish_date' => now()->subDay(),
    ]);

    app(DigestService::class)->sendDueDigests();

    assertDigestSentTo($user, function (WeeklyDigestMail $mail) use ($groupA, $groupB, $groupC): bool {
        $ids = $mail->summary->published->items->pluck('id');

        return $ids->contains($groupA->id)
            && $ids->contains($groupB->id)
            && ! $ids->contains($groupC->id);
    });
});

it('does not send at all to a user who belongs to no organization', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);

    app(DigestService::class)->sendDueDigests();

    Mail::assertNothingSent();
});

// --- Digest content: published / failed / scheduled buckets ------------

it('buckets published, failed, and scheduled groups correctly and respects each window', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($org, $user);

    $published = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::PUBLISHED,
        'publish_date' => now()->subDays(3),
    ]);
    $failed = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::ERROR,
        'error' => 'Publishing failed on the following channel(s): My Channel.',
        'notified_at' => now()->subDays(1),
    ]);
    $scheduled = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDays(3),
    ]);

    // Out of every window: published too long ago, and a QUEUE group
    // scheduled beyond the coming week.
    $stalePublished = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::PUBLISHED,
        'publish_date' => now()->subDays(30),
    ]);
    $farFuture = PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::QUEUE,
        'publish_date' => now()->addDays(30),
    ]);

    app(DigestService::class)->sendDueDigests();

    assertDigestSentTo($user, function (WeeklyDigestMail $mail) use ($published, $failed, $scheduled, $stalePublished, $farFuture): bool {
        $publishedIds = $mail->summary->published->items->pluck('id');
        $failedIds = $mail->summary->failed->items->pluck('id');
        $scheduledIds = $mail->summary->scheduled->items->pluck('id');

        return $publishedIds->contains($published->id)
            && ! $publishedIds->contains($stalePublished->id)
            && $failedIds->contains($failed->id)
            && $scheduledIds->contains($scheduled->id)
            && ! $scheduledIds->contains($farFuture->id);
    });
});

it('renders the failed section using notified_at, not the group\'s own (possibly unrelated) publish_date', function () {
    // Caught by manually checking a real send in Mailpit while building
    // this feature: DigestService::fetchSection() WINDOWS the failed
    // section by notified_at (see its docblock -- publish_date can be
    // arbitrarily stale by the time a retried group finally settles to
    // ERROR), but the view was, at first, still DISPLAYING publish_date
    // for every section including this one -- showing a date outside the
    // "this week" the heading promises. This renders the real Blade view
    // (not just inspects the DTO) so a regression here fails a test, not
    // just a human staring at Mailpit.
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create(['name' => 'Render Check Org']);
    $user = User::factory()->create(['timezone' => 'UTC', 'locale' => 'en', 'email_notifications' => true]);
    memberOf($org, $user);

    PostGroup::factory()->create([
        'organization_id' => $org->id,
        'state' => PostState::ERROR,
        'error' => 'Publishing failed on the following channel(s): Render Channel.',
        // Deliberately far outside the past-week window, unlike notified_at.
        'publish_date' => now()->addMonths(2),
        'notified_at' => now()->subDay(),
    ]);

    app(DigestService::class)->sendDueDigests();

    $sent = Mail::sent(WeeklyDigestMail::class)->first();
    $html = $sent->render();

    expect($html)->toContain('Render Check Org');
    expect($html)->toContain('Render Channel');
    // The notified_at date (yesterday) must appear...
    expect($html)->toContain(now()->subDay()->translatedFormat('d M Y'));
    // ...and the far-future publish_date must NOT.
    expect($html)->not->toContain(now()->addMonths(2)->translatedFormat('d M Y'));
});

it('still sends a digest for a week with nothing published, failed, or scheduled', function () {
    // Product decision: a user who belongs to a real organization still
    // gets the weekly email even when every section is empty -- silence
    // would be indistinguishable from a broken digest pipeline, and the
    // "failed" section in particular needs to keep arriving reliably so
    // its absence is trustworthy. See DigestService's docblock.
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();

    assertDigestSentTo($user, function (WeeklyDigestMail $mail): bool {
        return $mail->summary->isEmpty();
    });
});

// --- Idempotency: same-hour double run, and a real concurrent race -----

it('sends only once when the sweep runs twice in the same due hour', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();
    app(DigestService::class)->sendDueDigests();

    expect(digestSentCountTo($user))->toBe(1);
});

it('does not re-send the following hour of the same due day, only the digest already claimed', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();
    expect(digestSentCountTo($user))->toBe(1);

    // Still Monday, but the hour has rolled over to 09->10 boundary and
    // back due to a hypothetical extra scheduler tick within the same
    // local hour window.
    Carbon::setTestNow(mondayNineUtc()->addMinutes(40));
    app(DigestService::class)->sendDueDigests();

    expect(digestSentCountTo($user))->toBe(1);
});

it('sends again the following week, one cooldown period later', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($org, $user);

    app(DigestService::class)->sendDueDigests();
    expect(digestSentCountTo($user))->toBe(1);

    Carbon::setTestNow(mondayNineUtc()->addWeek());
    app(DigestService::class)->sendDueDigests();

    expect(digestSentCountTo($user))->toBe(2);
});

it('lets only one of two genuinely concurrent claims win, proven against real Postgres concurrency', function () {
    // Sequential double-calls (the tests above) only prove the SECOND
    // call sees the first call's already-committed write -- they can
    // never reproduce a true TOCTOU race, because each call's UPDATE
    // fully commits before the next one starts. This test forks two OS
    // processes that both race DigestService::sendDueDigests() against
    // the SAME user row via independent Postgres connections, proving
    // the atomic conditional UPDATE (not merely "usually runs fast
    // enough") is what makes this exactly-once. Same technique
    // MissedPostsTest's notify-race case uses for the identical class of
    // claim.
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    Carbon::setTestNow(mondayNineUtc());

    $owner = raceCreate(User::class, [
        'name' => 'Race User',
        'email' => 'digest-race-'.Str::random(10).'@example.com',
        'password' => 'irrelevant-hash',
        'timezone' => 'UTC',
        'email_notifications' => true,
    ]);
    $organization = raceCreate(Organization::class, [
        'name' => 'Race Co',
        'slug' => 'race-digest-'.Str::lower(Str::random(10)),
        'owner_id' => $owner->id,
    ]);
    raceCreate(OrganizationUser::class, [
        'organization_id' => $organization->id,
        'user_id' => $owner->id,
        'role' => OrganizationRole::ADMIN,
    ]);

    try {
        Mail::fake();

        $results = digestForkAndCollect([
            function () {
                app(DigestService::class)->sendDueDigests();

                return Mail::sent(WeeklyDigestMail::class)->count();
            },
            function () {
                app(DigestService::class)->sendDueDigests();

                return Mail::sent(WeeklyDigestMail::class)->count();
            },
        ]);

        expect($results[0])->toStartWith('ok:')
            ->and($results[1])->toStartWith('ok:');

        $sentCounts = array_map(fn (string $r): int => (int) substr($r, 3), $results);

        expect(array_sum($sentCounts))->toBe(1);

        $fresh = User::whereKey($owner->id)->first();
        expect($fresh->digest_sent_at)->not->toBeNull();
    } finally {
        DB::connection('race')->table('organization_user')->where('organization_id', $organization->id)->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});

// --- The actual scheduled entry point -----------------------------------

it('dispatches the sweep through SendDigestEmailJob exactly as the scheduler runs it, with no OrganizationContext set', function () {
    Mail::fake();
    Carbon::setTestNow(mondayNineUtc());

    $org = Organization::factory()->create();
    $user = User::factory()->create(['timezone' => 'UTC', 'email_notifications' => true]);
    memberOf($org, $user);

    OrganizationContext::clear();
    expect(OrganizationContext::get())->toBeNull();

    (new SendDigestEmailJob)->handle(app(DigestService::class));

    expect(OrganizationContext::get())->toBeNull();
    assertDigestSentTo($user);
});
