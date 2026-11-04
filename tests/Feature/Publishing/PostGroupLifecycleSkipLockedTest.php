<?php

use App\Enums\PostState;
use App\Models\Organization;
use App\Models\PostGroup;
use App\Models\User;
use App\Services\Posts\PostGroupLifecycle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * claimDue() claims with `FOR UPDATE SKIP LOCKED`: a group another transaction
 * is holding (an edit or a drag in flight, a second scheduler tick) is left for
 * later and the rest of the batch goes ahead. Without SKIP LOCKED the claimant
 * would wait for the holder and then claim the very group that was being edited.
 * A sequential test cannot tell the two apart, so this forks: one process holds
 * a due group's row lock, uncommitted, while another claims (see
 * raceConcurrently()). Who gets claimed is the assertion, not how long it took.
 *
 * The holder keeps the lock a moment after the claimant has started so the
 * claimant has reached it. If the claimant is slower the test still passes: it
 * can miss a regression, never invent one.
 */

it('claimDue() leaves a group another transaction holds and claims the rest of the batch', function () {
    config(['database.connections.race' => config('database.connections.pgsql')]);
    DB::purge('race');

    $owner = raceCreate(User::class, [
        'name' => 'Skip Owner',
        'email' => 'skip-'.Str::random(10).'@example.com',
        'password' => 'irrelevant-hash',
    ]);
    $organization = raceCreate(Organization::class, [
        'name' => 'Skip Co',
        'slug' => 'skip-'.Str::lower(Str::random(10)),
        'owner_id' => $owner->id,
    ]);
    $held = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'state' => PostState::SCHEDULED,
        'publish_date' => now()->subMinutes(5),
    ]);
    $free = raceCreate(PostGroup::class, [
        'organization_id' => $organization->id,
        'state' => PostState::SCHEDULED,
        'publish_date' => now()->subMinutes(4),
    ]);

    $dir = sys_get_temp_dir().'/pest-skip-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $holderHasLock = "{$dir}/holder-has-lock";
    $claimantStarting = "{$dir}/claimant-starting";
    $claimantResult = "{$dir}/claimant-result";

    try {
        raceConcurrently([
            // The holder: takes the due group's row lock and sits on it.
            function () use ($held, $holderHasLock, $claimantStarting): void {
                DB::transaction(function () use ($held, $holderHasLock, $claimantStarting): void {
                    PostGroup::withoutGlobalScope('organization')->whereKey($held->id)->lockForUpdate()->firstOrFail();
                    touch($holderHasLock);

                    raceAwaitBarrier($claimantStarting);
                    usleep(800_000);
                });
            },
            // The claimant: a scheduler tick while the holder is mid-flight.
            function () use ($holderHasLock, $claimantStarting, $claimantResult): void {
                raceAwaitBarrier($holderHasLock);
                touch($claimantStarting);

                $claimed = collect(app(PostGroupLifecycle::class)->claimDue(10, 60))->pluck('id')->sort()->values()->all();

                file_put_contents($claimantResult, json_encode($claimed));
            },
        ]);

        // Only the free group: the held one was skipped, not waited for.
        expect(json_decode((string) file_get_contents($claimantResult), true))->toBe([$free->id]);
    } finally {
        foreach ([$holderHasLock, $claimantStarting, $claimantResult] as $file) {
            @unlink($file);
        }
        @rmdir($dir);

        DB::connection('race')->table('posts')->whereIn('post_group_id', [$held->id, $free->id])->delete();
        DB::connection('race')->table('post_groups')->whereIn('id', [$held->id, $free->id])->delete();
        DB::connection('race')->table('organizations')->where('id', $organization->id)->delete();
        DB::connection('race')->table('users')->where('id', $owner->id)->delete();
    }
});
