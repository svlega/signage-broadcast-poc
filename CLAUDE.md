# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Repository layout

A digital signage proof of concept made of two separate apps plus Docker orchestration:

- `backend/` is a Laravel 13 app (PHP ^8.3; CI and Docker use 8.4). It holds three things: the kiosk API, Reverb broadcasting, and the MCP server. It also serves its own admin SPA from `resources/js/admin` (Vue 3, vue-router, Tailwind, built by Vite through laravel-vite-plugin).
- `frontend/` is the kiosk player, a standalone Vue 3 + Vite app on a separate origin. It has no router and no Pinia, on purpose.
- `docker-compose.yml` builds one backend image and runs it as several services: `migrate` (one-shot), `web` (nginx + php-fpm), `reverb`, `queue`, and `scheduler`. It also runs mysql, redis, and phpmyadmin. The API is on `:8090` and Reverb on `:6001`.

The root `README.md` explains the reasoning behind nearly every design choice. Read the relevant section before changing a subsystem.

## Commands

Backend (run in `backend/`):
```bash
php artisan test                                      # all tests (in-memory SQLite, sync queue, null broadcaster via phpunit.xml)
php artisan test --filter=DisplayScheduleTest         # single test class / method
vendor/bin/pint --test                                # style check (CI fails on drift); `vendor/bin/pint` to fix
composer dev                                          # serve + queue:listen + pail + vite, concurrently
php artisan reverb:start                              # WebSocket server (needs Redis)
php artisan signage:sync-google-announcement          # manual Google Doc → ticker sync
npm run type-check && npm run build                   # admin SPA
```

Frontend (run in `frontend/`):
```bash
npm run dev
npm run test                                          # vitest run
npx vitest run src/composables/__tests__/useSlideRotation.spec.ts   # single spec
npx vue-tsc --build                                   # type-check
npm run lint                                          # oxlint + eslint (both with --fix)
npm run build-only
```

Docker: copy `backend/.env.docker.example` to `backend/.env.docker` and fill in APP_KEY and the REVERB_* keys. Then run `docker compose up -d --build`, followed by `docker compose exec web php artisan db:seed --force` if you want demo data.

CI (`.github/workflows/ci.yml`) runs four jobs: backend tests + pint, admin type-check + build, frontend vue-tsc + vitest + build, and a Docker `runtime` target build.

## Architecture

**One global playlist.** `MediaItem` (video | slide | ticker) is the only content model. Content is never targeted to individual screens. `MediaItem::scopeOnAir()` filters to items that are active and inside their `starts_at`/`ends_at` daypart window, and does so in SQL. `PlayerDevice` and `PlayerHeartbeat` only record health: devices are upserted by `device_uid`, and heartbeats are append-only.

**Every write broadcasts.** `MediaItem::booted()` fires `ScheduleUpdated` on every save and delete. `ScheduleUpdated` is `ShouldBroadcast`, so it is queued, and it pushes the full on-air set on the public channel `signage.schedule` as event `schedule.updated`. So any code path that writes a `MediaItem` pushes to every screen: the admin CRUD, the MCP tools, and the Google sync. A write must also call `MediaItem::mintChecksum()`. The checksum is a random token, not a hash of the media, and it is what tells the player's cache to invalidate.

**Two transports carry the same data, both intentionally.** Reverb WebSocket push handles the fast path. The HTTP poll keeps working on networks that block WebSockets. The kiosk API lives in `routes/api.php`, under `/api/v1` (versioned because deployed players can't all be upgraded at once), and is unauthenticated with CORS enabled:
- `GET /display-schedule` returns the flat on-air list and honors ETag / `If-None-Match` with a 304.
- `GET /display-schedule/sync?since=` is the delta sync. It returns `{updated, deleted, server_time}` and uses soft deletes (`withTrashed`) to report removals. It is a separate route because its response shape differs.
- `POST /heartbeat`

**The admin panel uses the opposite model.** Its JSON API is under `/admin/api/*` in `routes/web.php` and runs through the `web` middleware group, so it gets session auth and CSRF. The `/admin/{any?}` catch-all serves the Blade shell for vue-router. The Google OAuth `connect`/`callback` routes must stay registered before that catch-all. The seeded login is `test@example.com` / `password`.

**MCP server.** `app/Mcp/Servers/SignageServer.php` uses `laravel/mcp` and is registered in `routes/ai.php`, which laravel/mcp auto-loads; it is not listed in `bootstrap/app.php`. It is exposed two ways:
- over HTTP at `/mcp/signage`, guarded by the `mcp.token` middleware (`EnsureValidMcpToken`, which checks a bearer token against `MCP_DEV_TOKEN`)
- over stdio as `signage`

The publish and schedule tools share the `Concerns/PublishesMediaItems` trait. That trait reuses `StoreMediaItemRequest::rules()` so MCP validation can't drift from the admin validation. Keep that reuse when adding tools.

**Google Workspace integration.** It is in `app/Services/Google/` and calls Google over raw HTTP with the `Http` facade (no google/apiclient), which makes it easy to test with `Http::fake()`. `GoogleIntegration` is a single row whose tokens are stored with the `encrypted` cast. The scheduler runs `signage:sync-google-announcement` every 15 minutes (`routes/console.php`); it upserts the one ticker `MediaItem` the integration owns.

## Kiosk player constraints (`frontend/`)

The player runs unattended 24/7 on low-power hardware. Treat these as rules:
- **Keep dependencies minimal.** Don't add a router, a store, or an IndexedDB wrapper library; parse cost at boot matters.
- **Avoid deep reactivity on content.** Schedule arrays are `shallowRef`s and are replaced wholesale. Items are `markRaw`'d.
- **Clean up every live resource inside the composable that creates it.** This covers timers, the Echo connection, and media elements. Use `onScopeDispose`, not `onUnmounted`, so cleanup also works outside a component and in tests. Outgoing `<video>` elements are explicitly paused, then have their `src` stripped, then `load()`ed, to free decoder buffers.
- **Offline support has two layers:**
  - `useContentManifest.ts` keeps per-item IndexedDB rows plus a `last_synced_at` watermark for delta sync. Bump `DB_VERSION` and handle the upgrade whenever its schema changes.
  - `public/service-worker.js` caches media cache-first and the app shell stale-while-revalidate. It deliberately never intercepts API calls.
- **Animate only `transform` and `opacity`, using CSS.** Slides use `<Transition mode="out-in">` so two media elements never decode at the same time.
- **Environment:** `VITE_API_BASE_URL` (default `http://localhost:8090`) and the `VITE_REVERB_*` values must match the backend's `.env`. A wrong API URL fails silently: the player just falls back to its cache.

## Conventions

The code explains why in comments that sit next to the decision: why this approach, what the alternative was, and what constraint forced it. When you make a similar design decision, document it the same way.
