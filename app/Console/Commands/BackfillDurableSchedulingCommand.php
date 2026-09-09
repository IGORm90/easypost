<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-review fix (P1): the durable-scheduling migration
 * (`0001_01_01_000027_add_durable_scheduling_to_post_groups_table`) used
 * to convert every existing `QUEUE` group (and its pending posts) to
 * `SCHEDULED` inside its own `up()`. That broke the hard "additive only"
 * migration rule, and — because `docs/DEPLOYMENT.md`'s deploy sequence
 * runs migrations before restarting `php`/`horizon`/`scheduler` — opened
 * a real window where the database had already been rewritten to the new
 * state model while every still-running process had OLD code loaded,
 * expecting `QUEUE` to mean "a delayed job exists somewhere". An old
 * delayed job firing in that window found its row `SCHEDULED`, silently
 * no-op'd, and nothing yet running (`posts:dispatch-due` doesn't exist in
 * the old code) would ever have picked it back up.
 *
 * This command is the replacement: a manual, one-off, idempotent step —
 * not a migration, not scheduled — run once by the operator, as an
 * explicit part of the same deploy as the new dispatcher.
 *
 * Post-review fix, round 2: this command used to be documented as safe
 * because it "runs AFTER the new dispatcher is already live" — but that
 * is a TIMING argument, and timing is not a safety property: the moment
 * the new code is live, it can ALSO be legitimately claiming rows into
 * `QUEUE` (`DueDispatchService`, `PostGroupService::claimAndDispatch()`,
 * `MissedPostsService::dispatchRedispatch()`), and a blind
 * `state = 'QUEUE'` filter cannot tell those apart from a genuine
 * pre-migration leftover, however long after restart this command
 * actually runs. Worse than a delay: if the blind `UPDATE` landed on a
 * group WHILE `PublishService::publishLockedChannel()` was mid-run for
 * it, `finalizeGroupState()`'s own re-check (`state !== QUEUE`) would
 * silently decline to finalize — the posts correctly `PUBLISHED`, the
 * group itself stranded at `SCHEDULED` forever, rendered by the
 * calendar/API as "never went out."
 *
 * The fix: filter on `dispatch_attempts = 0` too — a PROVABLE marker,
 * not a timing argument. Every claim path under the new code increments
 * `dispatch_attempts` in the exact same statement that sets
 * `state = 'QUEUE'`, so a row genuinely claimed by new code can never
 * have `dispatch_attempts = 0` while `QUEUE`. The migration that added
 * the column defaulted every existing row — every legacy `QUEUE`
 * leftover included — to 0. `state = 'QUEUE' AND dispatch_attempts = 0`
 * is therefore legacy by construction, regardless of when this command
 * runs relative to the deploy: seconds after restart or days later, a
 * concurrent new claim is structurally invisible to it.
 *
 * Idempotent by construction: a second run finds nothing left matching
 * that filter and converts zero rows — safe to re-run if the operator
 * is ever unsure whether it already ran.
 *
 * `posts` rows already carrying a `release_id` are excluded: a segment
 * mid-publish (state `QUEUE`, `release_id` NOT NULL — already sent to
 * the provider) is not "waiting", and must stay `QUEUE` so
 * `PostGroupService::guardEditable()`'s pending-post lock keeps
 * protecting it.
 *
 * ORDER MATTERS (post-review fix): `posts` must be converted BEFORE
 * `post_groups` — the `posts` `UPDATE`'s subquery identifies which
 * groups are legacy by re-checking `post_groups.state = 'QUEUE' AND
 * dispatch_attempts = 0` at the moment it runs. Flipping `post_groups`
 * first would mean that subquery finds nothing (those rows are already
 * `SCHEDULED` by then), leaving every legacy group's posts stranded at
 * `QUEUE` forever. This is not a tidiness note; reversing the two
 * statements is a real bug.
 *
 * Cross-tenant by design, like every other sweep in this codebase
 * (`MissedPostsService`, `TokenRefreshService::dueForRefresh()`) — raw
 * `DB::table()` queries throughout, never an Eloquent query the
 * `organization` global scope would silently filter to nothing.
 */
class BackfillDurableSchedulingCommand extends Command
{
    protected $signature = 'posts:backfill-durable-scheduling';

    protected $description = 'One-off: convert every QUEUE post group (and its pending posts) left over from before the durable-scheduling rewrite to SCHEDULED. Safe to run at any time — never touches a row genuinely claimed by the new dispatcher.';

    public function handle(): int
    {
        // Posts FIRST — see the class docblock's "ORDER MATTERS" note.
        $posts = DB::table('posts')
            ->where('state', 'QUEUE')
            ->whereNull('release_id')
            ->whereIn('post_group_id', function ($query): void {
                $query->select('id')
                    ->from('post_groups')
                    ->where('state', 'QUEUE')
                    ->where('dispatch_attempts', 0);
            })
            ->update(['state' => 'SCHEDULED']);

        $postGroups = DB::table('post_groups')
            ->where('state', 'QUEUE')
            ->where('dispatch_attempts', 0)
            ->update(['state' => 'SCHEDULED']);

        Log::info('posts.backfill_durable_scheduling', [
            'post_groups' => $postGroups,
            'posts' => $posts,
        ]);

        $this->info("Converted {$postGroups} post group(s) and {$posts} post(s) from QUEUE to SCHEDULED.");

        return self::SUCCESS;
    }
}
