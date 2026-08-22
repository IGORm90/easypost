# Manual acceptance checklist

This is the MVP acceptance checklist from the implementation plan, rewritten
as concrete steps. Each item says whether the automated suite already covers
it, or whether it genuinely needs a human with real provider credentials and
a browser.

Run `make up && make setup` first (see [README.md](../README.md)), and keep
Mailpit open at `http://localhost:8025` throughout — every email this app
sends in dev lands there, never at a real address.

## Covered by the automated suite — nothing to do by hand

These three are already enforced by CI-equivalent commands
(`./vendor/bin/pest`, `npx vitest run`). Re-running them is enough; there is
no separate manual step:

- **No Stripe/Cashier anywhere in the codebase.**
  ```bash
  grep -rniE "stripe|cashier" app config resources | grep -vE ':[0-9]+:\s*(//|\*|#)'
  ```
  Expected: no output. The trailing filter drops comment lines, because
  three lines in `app/Services/Billing/PlanRegistry.php:14`,
  `app/Console/Commands/SetOrganizationPlanCommand.php:11`, and
  `config/plans.php:4` are self-documenting comments that explicitly say
  neither Stripe nor Cashier is used, and `grep -i` matches those words
  inside the comments themselves. Run the first `grep` alone if you want
  to see those three hits directly — that's expected, and is not the
  same thing as Stripe/Cashier actually being wired into the code.
- **Interface has no raw color values, dual-theme safe.** `npx vitest run
  resources/js/__tests__/palette.spec.ts` — two specs, one failing on any
  hex literal (`#...`) in a `.vue` file, one failing on any default Tailwind
  palette class (`bg-gray-500`, `text-red-600`, etc.). A component can only
  reach color through the design tokens in `resources/css/app.css`, which is
  how both themes stay consistent without per-component light/dark branches.
- **Free-plan channel limit.** `./vendor/bin/pest --filter=PlanLimitServiceTest`
  — includes `it('throws when the channel limit is reached')` and a
  concurrency test (`pcntl_fork`) proving two simultaneous connect attempts
  can't both succeed past the limit.

## Needs a human (real provider credentials, a browser, visual judgment)

Everything below cannot be meaningfully automated without either real
third-party accounts or a human eyeball, and is not covered by the
automated suite.

### 1. Registration → email → verification → login, on a clean environment

1. `docker compose -f docker/compose.yaml down -v && make up && make setup`
   — a genuinely empty database.
2. Open the SPA, register a new account.
3. Open Mailpit (`http://localhost:8025`) — a verification email should have
   arrived within a few seconds.
4. Click the verification link in that email.
5. Log in with the same credentials.

Expected: each step succeeds with no error; the verification link resolves
without a 404/expired-signature error.

### 2. Invite a teammate; `USER` role cannot manage channels

1. As the org owner, go to Settings → Team, invite a second email address.
2. Check Mailpit for the invite email; open the link.
3. Accept the invite as a new (or already-registered) user, role `USER`.
4. Log in as that `USER`-role member. Go to Settings → Channels.

Expected: the `USER` member can *view* connected channels but has no
control to connect a new one or disconnect an existing one — those actions
are ADMIN/SUPERADMIN-only. Connecting (`auth-url`) is gated by `role:ADMIN`
middleware (`routes/api.php`); disconnecting is not — `DELETE
/api/integrations/{id}` carries no `role:` middleware at all, and the 403
instead comes from `IntegrationController::destroy()` calling
`findAuthorized($id, 'manage')`, which checks `IntegrationPolicy::manage()`.
Confirm this both by the UI hiding/disabling the controls and, if you want
to be thorough, by hitting `DELETE /api/integrations/{id}` directly as the
`USER` member and confirming a 403.

### 3. Each of the five providers actually connects and actually publishes

Needs real credentials — see [README.md](../README.md)'s "Obtaining
credentials for the five providers" section for how to get each one (a bot
token for Telegram, an app password for Bluesky, OAuth apps registered with
Discord/Slack, no pre-registration for Mastodon).

For **each** of Telegram, Mastodon, Bluesky, Discord, and Slack:

1. Settings → Channels → Connect → pick the provider, complete its flow.
2. Compose a short post targeting only that channel, publish immediately
   (or schedule ~1 minute out).
3. Confirm the message actually appears on the real channel (open Telegram/
   the Mastodon profile/Bluesky profile/Discord channel/Slack channel
   directly).

Expected: all five succeed independently. Do this one provider at a time —
a failure on one should not be read as a failure of the others.

### 4. One post, three channels, per-channel variation, threaded, at a scheduled time

1. Compose a new post targeting three different connected channels.
2. Give at least one channel a different message than the others (per-channel
   variation).
3. On at least one channel, add a second segment (a thread/reply chain).
4. Schedule it a few minutes in the future rather than publishing
   immediately.
5. Wait for the scheduled time to pass.

Expected: at the scheduled time (not before), all three channels receive
their respective content, and the threaded channel's segments appear as a
genuine reply chain (not three unrelated posts) on that platform.

### 5. Dragging a post in the calendar changes its publish time

1. Create a scheduled (not yet published) post a few days out.
2. On the Calendar page, drag it to a different day/time slot.
3. Reopen the post.

Expected: the post's scheduled time reflects the drop target, not the
original time. (The underlying redispatch/`queue_version` bump logic is
covered by `tests/Feature/Publishing/RescheduleTest.php`; what needs a human
is confirming the drag *gesture itself* — FullCalendar's interaction, not
just the API call it makes — works and feels right.)

### 5a. The list view pages through posts without repeating one

1. Switch the Calendar page from "Grid" to "List".
2. Filter by each status; check "Failed" in particular, which is the
   question the grid cannot answer at all (its error banner only ever
   speaks about the visible month).
3. With more than one page of posts — and, ideally, with several posts
   sharing one publish time, which is normal after a publish-now — press
   "Show more" until it disappears, then check no post appears twice and
   none is missing.
4. Forward the URL (it carries `?mode=list&state=…`) and open it fresh.

Expected: every post appears exactly once across the pages, and the
forwarded link opens on the same filtered list. Step 3 is the one worth a
human: `publish_date DESC` alone is not a total order, so a page boundary
falling inside a tie is precisely where a list silently duplicates or
drops rows. `tests/Feature/Posts/PostListTest.php` covers it on fabricated
data; this checks it on real data with real page sizes.

### 6. Media uploads, inserts into a post, and is sent to the social network

1. Compose a post, upload an image via the media picker.
2. Insert it into the post content, publish to a real connected channel.
3. Open that channel and confirm the image is actually attached to the
   published message, not just referenced/broken.

### 7. A channel with an expired token is flagged, and an email goes out

1. Take a connected channel and invalidate its credential on the provider's
   side (e.g. revoke the Telegram bot's access to the channel, or revoke the
   Discord/Slack OAuth grant from that platform's own settings).
2. Wait for (or manually trigger) the scheduler's token-refresh sweep, or
   attempt to publish through that channel.
3. Check the channel's status in Settings → Channels.
4. Check Mailpit for a "channel needs reconnecting" notification.

Expected: the channel is visibly marked as needing reconnection (not merely
failing silently on next publish), and a notification email arrives.

### 8. A failed publish shows up in an error list and can be retried

1. Force a publish failure (easiest: use item 7's invalidated channel, or a
   channel pointed at a since-deleted target).
2. Find the failed post in the UI.
3. Click retry.

Expected: the failure is visible with a human-readable reason (not a raw
stack trace), and retry actually re-attempts the send —
`POST /api/posts/{group}/retry` re-sends only the channels that failed, not
ones that already succeeded in a partially-failed group.

### 9. A job firing twice never publishes a post twice

This item explicitly says "verified through Horizon retry", not just unit
tests — the automated idempotency tests (`tests/Feature/Publishing/
PublishPostJobTest.php`, `RescheduleTest.php` — six tests between them,
covering `ShouldBeUnique`, row locking, `queue_version`, and state guards)
prove the *logic* is correct, but this step proves the *actual queue
infrastructure* behaves the same way under a real retry.

1. Open Horizon's dashboard (`http://localhost:8080/horizon`, or wherever
   it's routed) while a post is mid-publish or has just published.
2. Find the corresponding `PublishPostJob` entry and manually trigger a
   retry on it.

Expected: the retried job either declines to act (group already `PUBLISHED`,
or `queue_version` stale) or, if genuinely retrying a failed channel, does
not create a second published copy on the target platform — check the
channel directly, not just the app's own state.

### 10. Free-plan channel limit blocks a third channel; `org:plan` unblocks it

The *logic* is covered by `PlanLimitServiceTest` (see above); this step
confirms the operator workflow end-to-end:

1. Create (or use `make seed`'s demo org, then `php artisan org:plan
   {id} free`) an organization on the `free` plan with 2 channels already
   connected.
2. Try to connect a 3rd channel.
3. Note the organization's id, run:
   ```bash
   php artisan org:plan {organization-id} standard
   ```
4. Retry connecting the 3rd channel.

Expected: step 2 is blocked with a clear "plan limit reached" message; step 4
succeeds immediately after the plan change, with no restart or cache clear
needed.

### 11. Interface is checked in both themes

1. Toggle the app's theme (light/dark) — wherever that control lives in
   Settings.
2. Walk through Calendar, Channels, Media, and the post editor in both
   modes.

Expected, beyond the automated `palette.spec.ts` guard (no hex, no default
Tailwind classes — see above):
- Three background levels are visually distinguishable from each other in
  both themes (not just "readable," but actually distinct layers — page
  background vs. card vs. elevated/modal surface).
- Secondary/muted text is legible against its background in both themes —
  not just present, but actually readable at a glance.
- Post/channel status (published, scheduled, failed, needs reconnect) is
  never conveyed by color alone — an icon, label, or shape difference must
  also be present, so the status is legible to a colorblind user or in a
  screenshot printed in grayscale.

## Backend built, no UI yet

Recorded at MVP close-out (final review, batch 4) so the next reader does
not assume these are live. Each was reachable, tested and tenant-scoped;
what none of them had was anything in the SPA that called it. Anything
listed here is groundwork, not a feature — do not include it in an
acceptance pass, and do not treat its absence from the UI as a
regression.

**This list is now empty.** Tasks 1.1–1.7 built a caller for every entry
that used to be on it. Kept as a heading rather than deleted, because the
category is real and the next backend-ahead-of-UI surface belongs here.

What closed it, in case a reader is looking for where each one went:

| Surface | Routes | Caller |
| --- | --- | --- |
| Comments | `GET`/`POST /api/posts/{group}/comments` | Task 1.6 — `components/posts/CommentThread.vue`, mounted as a section of `PostEditorModal.vue` for a saved group. Append-only, matching an API with no PUT and no DELETE. |
| Duplicate a post | `POST /api/posts/{group}/duplicate` | Task 1.2 — the calendar's post context menu. |
| Impersonation | `POST /api/admin/impersonate/{user}`, `.../stop` | Task 1.7 — `pages/Admin/Users.vue` starts it, `components/admin/ImpersonationBanner.vue` shows it and stops it. Needed two new backend pieces of its own: `GET /api/admin/users` (there was no user list to pick from) and two new keys on `GET /api/auth/me` (`is_superadmin`, `impersonator`), without which the SPA could not tell it was impersonating at all. |
| Share-link minting | `POST /api/posts/{group}/share` | Task 1.1 — the editor header and the calendar context menu. |
| Tag / set / signature editing | `POST`/`PUT`/`DELETE` on `/api/tags`, `/api/sets`, `/api/signatures` | Tasks 1.3, 1.4, 1.5 — `components/tags/TagManager.vue`, `pages/Settings/Sets.vue`, `pages/Settings/Signatures.vue`. |

Two related notes, checked rather than carried over:

- `POST /api/media/from-url` **is** wired end to end — `stores/media.ts`'s
  `uploadFromUrl()` is called by `MediaUploader.vue`. An earlier review
  round recorded it as having no caller; that is no longer true. It does
  remain scope that was never in the plan.
- `PlanLimitService::usage()` and the failed-publish list were on this
  list and have since been built — see the plan panel on the team screen
  and the error banner above the calendar (§8).

## What the automated gates do not cover

Checked at MVP close-out (final review, batch 4). These are the seams where
a green suite genuinely means less than it appears to, so an acceptance
pass should exercise them by hand.

- **Calendar drag-and-drop, through FullCalendar itself.** The decision
  logic *is* gated: `CalendarGrid.spec` pulls the options object off a
  stubbed `<FullCalendar>` and calls `eventAllow` and `eventDrop` directly,
  covering the locked/unlocked branches, the 409 publish race, and the
  no-revert-on-success path. What no test covers is FullCalendar actually
  invoking those callbacks — the library is stubbed out, so a wrong option
  name or a version bump that renames a hook would pass. Drag a post in a
  browser (§3) rather than trusting the suite here.
- **`MvpFlowTest` is not end to end**, and its own docblock says so: it
  runs against the `fake` provider with `Queue::fake()`, so it proves the
  application's own wiring, never a real provider call or a real worker.
- **The real object-storage write path.** CI never provisions MinIO, so no
  CI job uploads a file to S3-compatible storage; the media tests use
  `Storage::fake('media')`. Uploading through the UI locally (§5) is the
  only thing that exercises it.
