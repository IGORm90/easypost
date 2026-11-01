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

### 3. Each provider actually connects and actually publishes

Needs real credentials — see [README.md](../README.md)'s "Obtaining
credentials" section for how to get each one (a bot token for Telegram, an
app password for Bluesky, an OAuth app registration for Reddit, a Meta app
for Facebook Page/Instagram/Threads).

For **each** of Telegram, Bluesky, Reddit and Facebook Page:

1. Settings → Channels → click "Connect" on that network's own card in the
   "Available networks" catalogue — its credential form opens directly, with
   no intermediate network picker (Task 37: the card the operator already
   clicked is the only choice being made; clicking it commits to that
   network). Reddit and Facebook Page are both `auth_type: 'oauth'` with no
   `auth_fields`, so both take the fast-connect branch: no modal at all, a
   new tab opens straight to the network's own authorize page. For Facebook
   Page, after the OAuth callback, open the new channel's settings and pick
   a page from the list `SupportsOptions::options('pages')` returns — this
   is the one manual step the connect flow itself does not do, on purpose
   (`FacebookPageProvider`'s docblock: the page token is resolved fresh per
   publish, never stored).
2. Compose a short post targeting only that channel, publish immediately
   (or schedule ~1 minute out). For Reddit, `PostSettingsFields.vue` will ask
   for a subreddit and a title before publish is allowed — pick a throwaway
   subreddit you moderate, not a real community, since this is a real post.
3. Confirm the message actually appears on the real channel (open the
   Telegram channel, the Bluesky profile, the Reddit submission, or the
   Facebook Page directly).

Expected: all four succeed independently. Do this one provider at a time —
a failure on one should not be read as a failure of the others.

For Reddit specifically, also do one thing none of the other gates
exercise: attach an image to the post and confirm it actually appears
(`RedditProvider::uploadMedia()`'s three-leg lease/upload/submit flow is
covered by `Http::fake()` in `tests/Unit/Social/RedditProviderTest.php`
only — no automated gate has ever sent it a real file, and CI provisions no
MinIO to even try). Then compose a second, threaded post (2+ segments) to
confirm the submission-plus-comments chain actually nests as replies on
Reddit, not as three separate top-level posts.

**Instagram and Threads cannot be accepted on this stack at all — do not
attempt them here.** Both publish by handing the Graph API a URL
(`MediaDto::$publicUrl`) it fetches for itself; neither ever receives an
uploaded file. The local default (`AWS_URL=http://localhost:9000/media`,
MinIO) is not reachable from Meta's servers, so a container never leaves
`IN_PROGRESS`/never gets created at all. These two channels are unit-tested
only (`tests/Unit/Social/InstagramProviderTest.php`,
`tests/Unit/Social/ThreadsProviderTest.php`, both `Http::fake()`-only) and
are acceptable **only on a deployment whose `AWS_URL` is publicly
routable** — the same caveat this document already carries for MinIO in
general, sharpened here because for these two networks it is not a gap in
coverage, it is the entire publish path. Publishing to a Facebook Page,
Instagram account or Threads account the operator does not own also
requires Meta app review, same as README's note for X/LinkedIn.

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

### 5b. Formatting and emoji reach the network the way the preview showed

1. Compose a post for a channel whose editor shows a format toolbar.
   Make one word bold, one italic, add a link, and drop an emoji into the
   middle of a sentence (not at the end — the caret is the part that
   breaks).
2. Add a second channel whose toolbar does not appear at all, and look at
   its preview: the same body must show its markers as plain characters
   there, with the "no formatting here" hint underneath.
3. Publish for real, and read both published posts in the networks
   themselves.
4. Publish a second post containing `5 < 6 & 7`, an underscore in
   `snake_case`, and no formatting at all.

Expected: bold is bold and the link is a link in the first network; the
second network shows the asterisks the preview promised; the emoji sits
where it was typed and the character counter agreed. Step 4 is the
regression check that matters most — it must publish exactly as it always
did, unescaped and unchanged, because that is the path every existing
post takes.

### 6. Media uploads, inserts into a post, and is sent to the social network

1. Compose a post, upload an image via the media picker.
2. Insert it into the post content, publish to a real connected channel.
3. Open that channel and confirm the image is actually attached to the
   published message, not just referenced/broken.

### 7. A channel with an expired token is flagged, and an email goes out

1. Take a connected channel and invalidate its credential on the provider's
   side (e.g. revoke the Telegram bot's access to the channel, or revoke the
   Bluesky app password).
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

### 10a. A repeating post keeps repeating, and stops when told to

Block 4. This is the item the automated suite can least be trusted on:
`RecurrenceTest` drives `PublishService` directly under `Queue::fake()`,
so the successor is created inside the test process, with the tenant
context the test happens to be in. Production creates it on a queue
worker with **no** organization context at all, where the `organization`
global scope returns zero rows — a mistake there produces a successor
with no content rather than an error.

1. Schedule a post on a real channel for a minute from now, set the
   repeat to "every few days", interval 1, and an end date three days
   out. Save.
2. Wait for Horizon to publish it. The calendar should show the original
   as published and exactly **one** new occurrence, one day later,
   carrying the same text, media and tags, and marked with the repeating
   indicator.
3. In Horizon, retry the completed `PublishPostJob`. No second occurrence
   appears (`repeat_spawned_at` has already been claimed).
4. Open the pending occurrence, set the repeat back to "Do not repeat"
   and save. Publish it. No further occurrence is created.
5. Drop the organization onto a plan whose monthly post quota is already
   spent (`php artisan org:plan {id} free`), then publish another
   repeating post. Expected: the post itself publishes normally and stays
   `PUBLISHED`, no job lands in `failed_jobs`, no successor is created,
   and the author receives the "a repeating post has stopped repeating"
   mail in Mailpit.

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

### Workspace creation

1. Sign in, open **Workspaces** in the sidebar (or the mobile menu), then
   follow **Create workspace**. The header retains the organization switcher
   but has no creation link. Check `/workspaces` and `/workspaces/create`
   directly: a signed-out visitor must be sent to login. With no memberships,
   the list shows an empty state and still offers creation. Workspaces stays
   highlighted in navigation on both screens.
2. Submit an empty or whitespace-only name, then a name longer than 255
   characters: show the field error and send no POST. Cancel returns to the
   workspace list without changing the selected workspace.
3. Enter a name and submit with Enter. While the request is pending, the form
   controls are disabled and a second submit must not create another workspace.
4. Inspect the real `POST /api/organizations` response: HTTP 201 with root
   `id`, `name`, `slug`, `created_at`, `updated_at` fields. The workspace must
   immediately appear selected in the header and its calendar must open.
5. Confirm calendar/channel requests carry the new `X-Organization`. Open
   Workspaces: the new entry is present and marked current. Select the original
   workspace in the header: the current marker moves immediately. Reload:
   the selection persists. The original workspace and its data remain intact,
   and neither workspace shows the other's posts or channels.
6. Check real API rejection paths: 422 for an empty name in an authenticated
   request and 401 without a session. Simulate an offline submit: keep the name
   and explain that the list should be checked after reloading before retrying,
   because a lost response does not prove that creation failed.
7. Check keyboard access, narrow mobile widths, EN/RU and both themes. Long
   workspace names must not push the header or list outside the viewport.
   On a phone, selecting Workspaces closes the mobile menu.

Vitest covers the form, validation, repeat-submit protection, navigation
failure recovery, route guards, navigation entry points and store updates
using a mocked API. List tests use real stores to check empty/populated states
and the current marker updating after selection changes. Pest
covers the real controller's authorization, response shape, validation and
membership assignment on the test database. Neither suite proves the live
cookie/CSRF flow or responsive browser layout; steps 1–7 remain manual checks.

### 12. A platform admin finds the monitoring dashboards and can answer the five questions

Needs a platform admin (`users.is_superadmin`). **Nothing in the app creates
one**: there is no command and no screen. Set the flag on an existing user in
`php artisan tinker` (`User::where('email', ...)->update(['is_superadmin' => true])`),
then sign in again.

1. Signed in as that user, the sidebar ends with a **Monitoring** block of
   three links: *Queues (Horizon)*, *Performance (Pulse)* and *Logs*. Open the
   menu on a phone width: the same block is there.
2. Each link opens its page **in a new tab** and the SPA keeps its place:
   `/horizon`, `/pulse`, `/log-viewer`. None of them shows a blank page.
3. Signed in as an ordinary user, or as an organization `SUPERADMIN` who is not
   a platform admin, the block is absent, and typing any of the three URLs
   gives 403. Signed out, the same.
4. Answer each from the dashboards, with a post scheduled and published first:
   are posts going out on time and how long does the `publish` queue wait
   (Horizon dashboard); which publishes failed and for which provider (Horizon
   Failed, search the `provider:` tag); which endpoints and queries are slow
   (Pulse); what happened to one post (Log Viewer, search its `post_group_id`);
   does the server have CPU, memory and disk (Pulse, Servers card).
5. Check EN and RU, and both themes, for the new block.

Vitest covers that the block renders for a platform admin in both menus with
three plain anchors (never router links), `target="_blank"` and
`rel="noopener"`, and is absent for everyone else. It does not render a real
page or follow a link: steps 1–5 are manual. The server side (the gate, the
403s) is Pest: `tests/Feature/Monitoring/DashboardAccessTest.php`.

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
| Workspace creation | `POST /api/organizations` | `pages/Workspaces/Index.vue`, reached through Workspaces in the sidebar/mobile menu, links to `pages/Workspaces/Create.vue`; creation selects the new workspace and opens its calendar. The header switcher remains for selection. |
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
- **Recurring publication never runs on a real worker in the suite.**
  `RecurrenceTest` clears `OrganizationContext` deliberately, which is
  the closest a test can get, but it still runs in-process with
  `Queue::fake()`: the successor is created synchronously rather than by
  a Horizon worker, and its own `PublishPostJob` is never executed. The
  one thing that proves the whole loop closes — an occurrence publishing
  and creating the next one, twice in a row, unattended — is §10a.

- **`MvpFlowTest` is not end to end**, and its own docblock says so: it
  runs against the `fake` provider with `Queue::fake()`, so it proves the
  application's own wiring, never a real provider call or a real worker.
- **What a network does with the markup we send it.** Block 3's provider
  tests assert the request body under `Http::fake` — that Telegram gets
  `<b>` with `parse_mode=HTML`, and that a post with no markup goes out
  byte-identical. That is our side of the
  contract only. Whether Telegram accepts that exact HTML, and whether
  what the reader sees matches the preview, no CI job can know: it takes a
  real token and a real message (§5b).
- **The real object-storage write path.** CI never provisions MinIO, so no
  CI job uploads a file to S3-compatible storage; the media tests use
  `Storage::fake('media')`. Uploading through the UI locally (§5) is the
  only thing that exercises it.
- **The fast-connect (new-tab OAuth) path is unit-tested only.** Task 37
  gave a provider declaring `auth_type: 'oauth'` with no `auth_fields` a
  dedicated flow: clicking its card's "Connect" opens the platform's login
  directly in a new tab (`useChannelConnect().startOAuthConnect()`), with
  no modal, and `pages/Integrations/Callback.vue` completes the connect in
  that tab via a `localStorage`-based handoff. Reddit is now the first
  shipped provider that actually takes this branch (Telegram and Bluesky
  both stay `auth_type: 'manual'`) — see §3's numbered check above for the
  manual walkthrough. Until that check has actually been run against a real
  Reddit account, `window.open`, the `channel_connect_pending` record
  crossing the tab boundary, and a real callback round-trip remain exercised
  only by stubs in `useChannelConnect.spec.ts`, `Channels/Index.spec.ts`
  and `Integrations/Callback.spec.ts` — none of which opens a real tab or
  talks to a real authorization server.
- **Reddit's media upload and submission-plus-comments thread are
  `Http::fake()`-only.** `RedditProvider::uploadMedia()`'s three-leg
  lease/S3-upload/submit flow and `RedditProvider::post()`'s
  submission-then-comment-chain are both covered end to end in
  `tests/Unit/Social/RedditProviderTest.php`, but every one of those tests
  fakes Reddit's HTTP responses — none of them has ever sent a byte to a
  real Reddit API or a real S3 bucket. CI provisions no MinIO for the
  application's own media pipeline either, so the whole chain (upload a
  real image in the composer → `MediaTempFiles` copies it → `PublishService`
  hands it to `uploadMedia()` → the presigned POST actually reaches S3 →
  `post()` references the uploaded asset) is untested by any automated
  gate. §3's numbered Reddit check above is the only thing that exercises
  it. Reddit video posts are not supported at all (`maxVideos: 0` in
  `RedditProvider::capabilities()`) — Reddit's video pipeline needs a
  separate, more involved upload API this change does not implement.
- **The three Meta providers' whole publish path is `Http::fake()`-only,
  and for two of them that is not a gap — it is the ceiling.**
  `FacebookPageProvider`, `InstagramProvider` and `ThreadsProvider` are
  each covered end to end in their own `tests/Unit/Social/*ProviderTest.php`
  (OAuth exchange, the long-lived-token dance, error mapping, the
  container-poll-publish sequence, Threads' reply chain and partial
  failure), but no automated gate has ever sent a byte to a real Meta
  endpoint. Facebook Page is a real upload
  (`uploadHttp()`/`ReadsMediaFiles`, same shape as every other provider's
  media path) and can be accepted the normal way once §3's Facebook Page
  check has run. Instagram and Threads are different in kind, not just
  untested: both build every post from `MediaDto::$publicUrl`, a URL Meta
  fetches for itself, and never receive an upload at all — see §3's note
  above. On this stack's local MinIO (`AWS_URL=http://localhost:9000/media`)
  that URL is not reachable from the outside, so these two channels cannot
  be accepted here regardless of how thorough the walkthrough is; a real
  acceptance pass for them needs a deployment with a publicly routable
  `AWS_URL`, full stop.
- **Structured logging is proven at the record level, never through real
  stdout, real JSON framing via the Docker log driver, or the nginx
  access log format.** `LogChannelsTest`, `JobInstrumentationTest`,
  `LogContextTest`/`LogContextPropagationTest` and `PublishLoggingTest`
  all assert against a real Monolog channel writing to a temp file or a
  mocked `Log` facade — never against what actually lands in `docker
  compose logs`, and never against whether the container runtime's
  json-file driver frames each line correctly. `docker/nginx/default.conf`'s
  JSON access log, its `$easypost_path`/`$easypost_loggable` capture (see
  that file's comments for why a naive `$uri`- or
  `location = /up`-based approach silently breaks it), and the
  `fastcgi_param HTTP_X_REQUEST_ID` join with the application's own
  `request_id` were verified by hand against the running stack while this
  was built, but no automated gate re-checks any of it. Re-verify by hand
  after any nginx or Docker logging-driver change: hit a real endpoint and
  confirm the same request id appears in both the nginx access line and
  the application log line.
- **Log Viewer is checked at the HTTP layer, never in a browser.**
  `DashboardAccessTest` proves the gate (guest, ordinary user and
  organization SUPERADMIN get 403; a platform admin gets the page and the
  JSON API) and that deletion is off, and `ViewerLogChannelTest` /
  `LogSecretsTest` prove the `viewer` file is redacted, parseable and
  world-appendable. Nothing renders the Vue page. Before relying on it, sign
  in as a platform admin, open `/log-viewer`, and confirm: the page loads
  with styles (assets are inlined, so a blank page means the script was
  blocked), `viewer-YYYY-MM-DD.log` is listed, a `publish.attempt` line from
  a **job** (written by the `horizon` container, not the web container) is
  visible and searchable by `post_group_id`, no delete button is shown, and
  its API calls succeed (the JSON API only treats a request as signed in when
  the browser sends `Referer`/`Origin`; `curl` without them gets 403).
  The shared `easypost-logs` volume is not exercised by the suite either:
  `docker compose exec horizon ls storage/logs` and `exec php ls
  storage/logs` must list the same files.
- **Pulse is verified at the gate and config level, and over HTTP by hand.**
  `PULSE_ENABLED` is `false` under phpunit, so the `/pulse` route does not
  exist in the suite: `DashboardAccessTest` asserts the `viewPulse` gate
  itself and `PulseConfigTest` the recorder config (thresholds, ignore list,
  host-only URL grouping, seven-day retention, `procps` in the image). Nothing
  proves the route is wired to that gate, that the Livewire cards load and
  refresh, or that `pulse:check` records. Before relying on it: as a guest
  `/pulse` is 403; as a platform admin it loads with all nine cards; the
  Servers card shows `easypost` (or `PULSE_SERVER_NAME`) with non-zero memory
  and disk, and exactly one server after `docker compose up -d --force-recreate
  pulse`; `docker compose logs pulse` has no `top: not found`; and after a
  deliberately slow request or job the Slow cards show it. Cards load through
  Livewire POSTs, so this needs a real browser session.
- **Healthchecks, limits and restarts are asserted from the file, not from
  Docker.** `ComposeServicesTest` proves `docker/compose.yaml` declares them;
  it cannot prove Docker applies them or that a check turns red when it should.
  Verified by hand while building this, and worth repeating after any change to
  a check: `docker compose ps` shows every service `(healthy)` (the scheduler
  after its first minute); `horizon:pause` run *inside the horizon container*
  (it signals by PID, so from `php` it reports "No processes to pause") flips
  `horizon:status` to exit 1 and `horizon:continue` back to 0; backdating
  `/tmp/scheduler-heartbeat` ten minutes makes the scheduler check fail; and a
  service whose process exits on its own (`redis-cli shutdown nosave`) comes
  back with `RestartCount` 1. `docker kill` is **not** a valid crash test: it
  counts as a manual stop and is never restarted. Not exercised: the nginx and
  php checks failing (stopping `php` is the trap in DEPLOYMENT.md
  Troubleshooting 1), the `mem_limit` OOM-kill itself, and the Redis 200mb
  refusal path.
