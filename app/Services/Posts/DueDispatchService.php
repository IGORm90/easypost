<?php

namespace App\Services\Posts;

use App\Jobs\PublishPostJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Durable-scheduling rewrite: the atomic claim step behind
 * `posts:dispatch-due` (see `App\Console\Commands\DispatchDuePostsCommand`).
 *
 * Before this, a scheduled post was dispatched as a delayed job the
 * instant it was saved (`PostGroupService::dispatchPublishIfQueued()`,
 * now deleted) — meaning Postgres held no durable record that a
 * publish was ever going to happen; only Redis did, for however many
 * weeks out the user scheduled it. This service flips that: Postgres
 * is the source of truth (`SCHEDULED` rows with a `publish_date`), and
 * Redis is a transport for work due in the next
 * `publishing.dispatch_lookahead_seconds`. See `App\Enums\PostState`'s
 * docblock for the full `SCHEDULED` vs `QUEUE` split.
 *
 * Runs with NO `OrganizationContext` set — same reasoning as
 * `MissedPostsService::redispatchOrphaned()` and
 * `TokenRefreshService::dueForRefresh()`: this is a scheduled command,
 * deliberately cross-tenant, so raw `DB::` queries are used throughout
 * rather than an Eloquent query the `organization` global scope would
 * silently filter to nothing.
 */
class DueDispatchService
{
    /**
     * Claims every `SCHEDULED` group due within the lookahead window, in
     * bounded batches, and dispatches a `PublishPostJob` for each —
     * ID-only, delayed until the row's own `publish_date`.
     *
     * The delay matters (post-review fix, P0): the whole point of
     * `dispatch_lookahead_seconds` is to claim a row up to ~90s BEFORE it
     * is actually due, so a per-minute tick never leaves a post waiting
     * for the NEXT tick just because it landed a few seconds past this
     * one. Dispatching that claim immediately, with no delay, ran
     * straight into `PostGroupLifecycle::isDueNow()`'s 5-second future
     * grace: a row claimed 30-90s early would run right away, find
     * itself not yet due, and un-claim itself straight back to
     * `SCHEDULED` (`PostGroupLifecycle::claim()`'s guard 3) — bouncing
     * claim/un-claim across ticks, burning `dispatch_attempts` for a
     * completely ordinary, on-time post, and publishing up to a full
     * tick late. `->delay($row->publish_date)` fixes this directly:
     * Laravel dispatches immediately for a past/near timestamp, so this
     * is safe to call unconditionally, and the maximum possible delay is
     * bounded by the lookahead itself (~90s) — nowhere near the
     * multi-week delays this whole rewrite exists to eliminate.
     */
    public function dispatchDue(): int
    {
        $batchSize = (int) config('publishing.dispatch_batch_size');
        $maxBatches = (int) config('publishing.dispatch_max_batches');
        $lookahead = (int) config('publishing.dispatch_lookahead_seconds');

        $claimed = [];

        for ($batch = 0; $batch < $maxBatches; $batch++) {
            $rows = $this->claimBatch($batchSize, $lookahead);

            if ($rows === []) {
                break;
            }

            array_push($claimed, ...$rows);

            if (count($rows) < $batchSize) {
                break;
            }
        }

        // Dispatched only after every claiming transaction below has
        // already committed — see this class's docblock and
        // PostGroupLifecycle::dispatch()'s docblock for why a
        // dispatch from inside a still-open transaction risks a worker
        // querying for a row the transaction has not made visible yet.
        foreach ($claimed as $row) {
            PublishPostJob::dispatch($row->id, $row->queue_version)
                ->delay(Carbon::parse($row->publish_date));
        }

        Log::info('publish.dispatch_due', ['claimed' => count($claimed)]);

        return count($claimed);
    }

    /**
     * One atomic claim: `UPDATE ... WHERE id IN (SELECT ... FOR UPDATE
     * SKIP LOCKED) RETURNING id, queue_version, publish_date`, never a
     * separate select-then-update — the `FOR UPDATE SKIP LOCKED`
     * subquery is what makes two overlapping calls (two scheduler ticks,
     * or the concurrency test) claim disjoint sets instead of racing
     * each other onto the same row.
     *
     * `DB::select()`, not `DB::update()`: Postgres' `RETURNING` clause
     * makes an UPDATE behave like a query that returns rows, and
     * `DB::select()` is the driver method that fetches them — `DB::update()`
     * only returns an affected-row COUNT, discarding exactly the ids,
     * queue_versions and publish_dates this method needs to hand to
     * `PublishPostJob::dispatch()->delay()`.
     *
     * @return list<object{id: string, queue_version: int, publish_date: string}>
     */
    private function claimBatch(int $batchSize, int $lookahead): array
    {
        return DB::transaction(function () use ($batchSize, $lookahead) {
            $rows = DB::select(
                <<<'SQL'
                    UPDATE post_groups
                    SET state = 'QUEUE',
                        last_dispatched_at = now(),
                        dispatch_attempts = dispatch_attempts + 1
                    WHERE id IN (
                        SELECT id FROM post_groups
                        WHERE state = 'SCHEDULED'
                          AND publish_date <= now() + (? || ' seconds')::interval
                        ORDER BY publish_date
                        LIMIT ?
                        FOR UPDATE SKIP LOCKED
                    )
                    RETURNING id, queue_version, publish_date
                SQL,
                [$lookahead, $batchSize]
            );

            if ($rows !== []) {
                $ids = array_map(static fn (object $row): string => $row->id, $rows);

                // The group's pending posts move with it — PublishService
                // re-reads posts by post_group_id, not by their own
                // state, but keeping both in sync is what lets
                // guardEditable()'s pending-post lock
                // (state=QUEUE AND release_id IS NULL) correctly protect
                // only genuinely in-flight segments, and nothing else.
                DB::table('posts')
                    ->whereIn('post_group_id', $ids)
                    ->where('state', 'SCHEDULED')
                    ->update(['state' => 'QUEUE']);
            }

            return $rows;
        });
    }
}
