<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\PostGroupLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The group's row lock is the whole point of the claim guard: while a writer
 * holds it (an edit or a reschedule in flight, not yet committed), a publisher
 * must WAIT, and then act on what the writer committed. Every other test of the
 * guard commits the competing change BEFORE the guard reads, so none of them
 * would notice if the lock were dropped — a plain READ COMMITTED read returns
 * the same answer once the writer is done. Only a writer that is still in
 * flight while the publisher reads tells the two apart, which needs two real
 * database connections, so this forks (see raceConcurrently()).
 *
 * The only wait is the writer holding the lock a moment so the claimant has
 * reached it. If the claimant is slower than that the test still passes — it
 * can only miss a regression, never invent one.
 */

it('claim() waits for a writer that holds the group row lock and then acts on what it committed', function () {
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, [
        'name' => 'Lock Owner',
        'email' => 'lock-'.Str::random(10).'@example.com',
        'password' => 'irrelevant-hash',
    ]);
    $organization = raceCreate(Organization::class, [
        'name' => 'Lock Co',
        'slug' => 'lock-'.Str::lower(Str::random(10)),
        'owner_id' => $owner->id,
    ]);
    $group = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'state' => PostState::QUEUE,
        'publish_date' => now(),
        'queue_version' => 1,
    ]);

    $dir = sys_get_temp_dir().'/pest-lock-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $writerHasLock = "{$dir}/writer-has-lock";
    $claimantStarting = "{$dir}/claimant-starting";
    $claimantResult = "{$dir}/claimant-result";

    try {
        raceConcurrently([
            // The writer: takes the row lock, holds it while the claimant arrives, then
            // un-claims the group (a calendar drag) and commits.
            function () use ($group, $writerHasLock, $claimantStarting): void {
                DB::transaction(function () use ($group, $writerHasLock, $claimantStarting): void {
                    PostGroup::withoutGlobalScope('organization')->whereKey($group->id)->lockForUpdate()->firstOrFail();
                    touch($writerHasLock);

                    raceAwaitBarrier($claimantStarting);
                    usleep(600_000);

                    DB::table('post_groups')->where('id', $group->id)->update(['state' => PostState::SCHEDULED->value]);
                });
            },
            // The claimant: a publish job arriving while the writer is mid-flight.
            function () use ($group, $writerHasLock, $claimantStarting, $claimantResult): void {
                raceAwaitBarrier($writerHasLock);
                touch($claimantStarting);

                $claimed = app(PostGroupLifecycle::class)->claim($group->id, 1);

                file_put_contents($claimantResult, $claimed === null ? 'refused' : 'claimed');
            },
        ]);

        // Waited for the writer, then saw SCHEDULED: nothing to publish. Without the lock it reads
        // the old committed QUEUE and would publish a group that is being un-claimed.
        expect(file_get_contents($claimantResult))->toBe('refused');
    } finally {
        foreach ([$writerHasLock, $claimantStarting, $claimantResult] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        DB::connection('race')->table('post_groups')->where('id', $group->id)->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});
