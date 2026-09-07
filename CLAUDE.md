# CLAUDE.md — EasyPost

EasyPost is a multi-tenant social-post scheduler. You connect Telegram and Bluesky channels,
compose a post (with per-channel variations and threads), schedule it, and watch it publish. **Laravel 13 REST API + a separate Vue 3 SPA** — no Inertia; the SPA talks to the API
over axios with Sanctum cookie auth, and everything is scoped to an `Organization`.

## The project guide

@AGENTS.md

That file is the single source of truth for this repo: stack, architecture, domain vocabulary,
tenancy, the hard rules (each backed by a test that fails the build), testing, CI gates, code style,
naming, git conventions and the review checklist. **Read it before writing code.** Do not restate its
rules here — keep them in one place so they cannot drift.

Deeper references, per area:

- `README.md` — env-var tables, per-provider credential setup, quick start, known issues.
- `docs/ACCEPTANCE.md` — the manual acceptance checklist, what is backend-built with no UI yet, and
  what the automated gates do *not* prove.
- `docs/DEPLOYMENT.md` — the nine-service topology, the "APP_KEY is permanent" warning, queue
  timeout rules.
- `color.md` — the design-token palette spec (Russian). Mechanically enforced.
- `LARAVEL_REWRITE_PLAN.md` — the original Postiz inventory and its mapping onto this rewrite.

## Working agreements

- **Always ask before committing, and before pushing.** Never auto-commit.
- **After finishing a task, propose a commit message** — end the task with a ready-to-use
  Conventional Commits message (lowercase, scoped, no ticket key, per AGENTS.md § Git, with the
  explanatory body and its closing `Verified: …` line). Propose it; do not run `git commit` until
  the user says so.
- **Before claiming something works, run the gates** — don't infer from "the code looks right".
  Then state what you verified and what you did not. Full discipline in AGENTS.md
  § "Before claiming something works".

  ```bash
  ./vendor/bin/pint --test                              # style
  php -d memory_limit=1G ./vendor/bin/phpstan analyse   # level 6; the memory flag is not optional
  ./vendor/bin/pest                                     # backend suite
  npm run typecheck && npx vitest run && npm run build  # frontend
  ```

- **TDD is mandatory: write the failing test first, then the code.** For every feature and every
  bugfix — add or change a test that fails for the right reason, watch it fail, then write the
  minimum code to make it pass, then refactor. A bugfix without a test that reproduced the bug is
  not a fix. Do not write implementation code for which no test was written first; if a change
  genuinely cannot be tested (a build config, a doc), say so explicitly instead of skipping quietly.

- **After any frontend change, verify it in a real browser via the Claude in Chrome extension.**
  Vitest and `npm run build` prove the code compiles and the units behave; they do not prove the
  screen renders, the request goes out, or the SPA route even mounts. So once the feature is in,
  open the app at `http://localhost:8080` in a tab, walk the actual flow you changed (including the
  error path), read the console for errors and the network panel for the API calls, and only then
  call it done. Report what you clicked and what you saw. If the browser check is impossible —
  the extension is unavailable, the stack is not up — say so explicitly instead of skipping it
  silently.

- `make setup` is the only setup path. **Never run `php artisan key:generate` on an install that
  already has connected channels** — it makes every encrypted `Integration` token undecryptable,
  permanently.
- The local branch is `master`, but CI triggers on `main`. Pushes to `master` don't run CI.

## Skills

This repo ships framework skills under `.claude/skills/` — `laravel`, `laravel-best-practices`,
`pest-testing`, `vuejs-typescript-best-practices`, `tailwindcss-development`,
`socialite-development`. Prefer them for framework-level work. (`inertia-vue-development` is present
but does not apply — this app has no Inertia.)
