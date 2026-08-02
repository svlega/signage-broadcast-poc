# Signage Broadcast Platform — PoC

A minimal, production-shaped proof of concept for a digital signage platform:
a Laravel broadcast engine feeding a Vue 3 player designed to run unattended,
24/7, on constrained kiosk hardware (Android WebView, ChromeOS, low-power
SoC boxes).

Built to demonstrate the specific engineering discipline that low-power,
mission-critical embedded web apps require — not a generic CRUD demo. Every
decision below has a stated reason; the reason is the actual point of this
repo.

## The core constraint

A signage player isn't a page someone reloads if it misbehaves — it's a
screen bolted to a wall, running the same tab for weeks, with nobody
watching it fail. That reframes ordinary web engineering concerns:

| Ordinary web app | Signage player |
|---|---|
| A memory leak is a performance bug | A memory leak is an eventual outage (OOM-kill) |
| A network blip shows a spinner | A network blip must be invisible — cached content keeps playing |
| Bundle size affects Lighthouse score | Bundle size affects boot time on a weak ARM SoC, every time it's rebooted |
| The user reloads if something looks wrong | Nobody is there to reload it |

Everything in `frontend/` optimizes for these constraints first, feature
richness second.

## Architecture

```
┌─────────────────────┐   HTTP (poll + heartbeat)   ┌──────────────────────┐
│                      │ ──────────────────────────▶ │                      │
│   Vue 3 Player       │                              │  Laravel API (v1)    │
│   (kiosk tab)        │ ◀── WebSocket (Reverb) ───── │  + Reverb + Redis    │
│                      │      live schedule push      │                      │
└──────────┬───────────┘                              └──────────┬───────────┘
           │                                                     │
           ▼                                                     ▼
   IndexedDB (schedule)                                    SQLite/MySQL
   Cache Storage (media bytes, via Service Worker)          (media_items, player_devices, player_heartbeats)
```

Two transports carry the same data on purpose: the WebSocket push (Reverb,
scaled via Redis pub/sub) is what makes an urgent content change reach every
screen in ~seconds, while the HTTP poll underneath it is what keeps the
screen updating even on a network where outbound WebSockets are blocked but
HTTPS isn't (common on locked-down retail/corporate Wi-Fi). Neither
transport is a fallback bolted on after the fact — both were designed in
from the start as the two ways a kiosk actually loses connectivity.

## 1. Laravel Backend — `backend/`

```
backend/
├── app/
│   ├── Events/ScheduleUpdated.php          # broadcasts on-air changes over Reverb (Redis-scaled)
│   ├── Http/
│   │   ├── Controllers/Api/V1/
│   │   │   ├── DisplayScheduleController.php  # GET /api/v1/display-schedule (ETag/304-aware)
│   │   │   └── HeartbeatController.php        # POST /api/v1/heartbeat
│   │   ├── Requests/StoreHeartbeatRequest.php
│   │   └── Resources/MediaItemResource.php     # flat, minimal API shape
│   └── Models/
│       ├── MediaItem.php       # video | slide | ticker, dayparted via onAir() scope
│       ├── PlayerDevice.php    # one row per physical screen
│       └── PlayerHeartbeat.php # append-only health time series
├── database/migrations/        # media_items, player_devices, player_heartbeats
├── database/seeders/MediaItemSeeder.php
├── docker/                     # nginx conf, entrypoint, opcache.ini — see "Running the backend with Docker" below
├── Dockerfile
└── routes/
    ├── api.php                 # /api/v1/* — versioned so old player builds keep working
    └── channels.php            # signage.schedule (public channel, kiosk has no session)
```

**Endpoints**

- `GET /api/v1/display-schedule` — current on-air playlist. Filters
  active/dayparted items in SQL, and supports `If-None-Match` → `304` so a
  poll that finds nothing changed costs the device zero JSON parsing and
  the network zero bytes.
- `POST /api/v1/heartbeat` — device health sample (memory used/limit,
  uptime, status). Append-only by design: a time series is what lets ops
  catch a slow memory-creep *before* it OOM-kills a player, days or weeks
  into a run nobody is watching.

**Real-time push**: `MediaItem::booted()` fires `ScheduleUpdated` on every
save/delete. The event implements `ShouldBroadcast` (queued, not
`ShouldBroadcastNow`) onto the Redis queue, so a slow WebSocket fan-out to a
large fleet never blocks the admin request that triggered it. Reverb runs
with `REVERB_SCALING_ENABLED=true`, pushing its internal pub/sub through
the same Redis instance — the mechanism that lets more than one Reverb
process (behind a load balancer, for a fleet too large for one) still
deliver a broadcast to every screen.

## 2. Vue 3 Frontend — `frontend/`

```
frontend/
├── public/
│   └── service-worker.js         # cache-first (media) / stale-while-revalidate (app shell)
├── src/
│   ├── components/
│   │   ├── SignagePlayer.vue     # root: composition + cleanup wiring
│   │   └── NewsTicker.vue        # GPU-accelerated scrolling ticker
│   ├── composables/
│   │   ├── useDisplaySchedule.ts # ETag-aware fetch + poll fallback
│   │   ├── useSlideRotation.ts   # rotation timer; video drives itself via `ended`
│   │   ├── useSignageSocket.ts   # Reverb/Echo connection, full lifecycle cleanup
│   │   ├── useHeartbeat.ts       # performance.memory → POST /heartbeat every 30s
│   │   ├── useOfflineCache.ts    # IndexedDB: last-known-good schedule metadata
│   │   └── __tests__/useSlideRotation.spec.ts
│   ├── types/media.ts
│   ├── App.vue                   # no router, no store — one always-on view
│   └── main.ts
```

No Vue Router, no Pinia. This player renders exactly one view; both
libraries are genuinely good tools that would sit idle here, and every
idle dependency is JS the SoC still has to parse and hold in memory for the
entire 24/7 run. Cutting them is a deliberate low-power decision, not an
oversight — see the comment in `main.ts`.

### Reactivity optimization

`useDisplaySchedule`'s playlist is a `shallowRef<MediaItem[]>`, not a deep
`ref`. The array reference is swapped wholesale on every update; a deep
`ref` would recursively wrap every `MediaItem` — and every property on
it — in a reactive `Proxy`, just so the app can mutate fields it never
actually mutates. Every item is also `markRaw`'d, so even a template's
first read of a field doesn't trigger proxy-wrapping. The same pattern
covers the preloaded-image cache in `SignagePlayer.vue` (a `Map` of
`markRaw`'d `HTMLImageElement`s, capped at 3 entries).

### Memory-leak prevention

Every composable that creates a live resource cleans it up in
`onUnmounted`, colocated with the resource instead of duplicated in the
caller:

- `useDisplaySchedule` — clears the poll `setTimeout` chain (a dangling
  reschedule-on-fire timer is the easiest way to slowly wind up a 24/7 tab).
- `useSignageSocket` — leaves the Reverb channel and disconnects Echo.
- `useHeartbeat` — clears its interval.
- `SignagePlayer.vue` — explicitly `pause()` + strip `src` + `load()` on
  the outgoing `<video>` element before it's replaced, so Chromium
  releases hardware decoder buffers immediately rather than waiting on GC;
  the same teardown runs for any preloaded `<img>` bitmaps still cached.

### Offline resilience (two layers)

1. **IndexedDB** (`useOfflineCache.ts`) — the last successfully fetched
   schedule *metadata* (not media bytes), so a cold boot with no network
   still renders the last known-good playlist instead of an empty screen.
   Chosen over `localStorage` because localStorage is synchronous (blocks
   the main thread the video/rotation timers also run on) and string-only
   (forces a full `JSON.stringify`/`parse` on every access).
2. **Service Worker + Cache Storage** (`public/service-worker.js`) — the
   actual media bytes (images/video). Cache-first for media (it rarely
   changes; the `checksum` field is how a real change would be noticed),
   stale-while-revalidate for the app shell so a reboot mid-outage repaints
   instantly from cache while quietly re-checking for a newer build. The
   API itself is deliberately *never* intercepted here — `useDisplaySchedule`
   already has its own explicit offline/online state; a Service Worker
   silently serving stale JSON underneath it would hide connectivity loss
   instead of surfacing it.

### Hardware acceleration

`NewsTicker.vue`'s scroll and the slide crossfade both animate `transform`
/`opacity` only (never `left`/`width`/layout properties), with
`translate3d` + `will-change` to force GPU compositing ahead of the first
frame. Both are pure CSS `@keyframes`/`transition` — zero JS execution per
frame, running on the compositor thread so they stay smooth even while the
JS thread is busy parsing a schedule poll or a WebSocket message. Slide
transitions use `<Transition mode="out-in">` specifically so there is never
a moment with two `<video>`/`<img>` elements decoding simultaneously —
correctness for peak memory, at the cost of a ~0.4s gap.

### Real-time updates without accumulation

`useSignageSocket` holds one Echo/Reverb connection for the component's
entire lifetime — no reconnect-and-leak-the-old-socket pattern, no
per-message listener registration. The HTTP poll in `useDisplaySchedule`
keeps running underneath it as a fallback, so losing the socket degrades to
"updates every 30s" rather than "stops updating."

## 3. Admin Dashboard — `backend/resources/js/admin`

A same-origin Vue 3 SPA served by Laravel itself (Blade shell + Vite),
for managing the schedule and watching fleet health:

```
backend/resources/js/admin/
├── router.ts                       # real vue-router — see below for why that's fine here
├── App.vue                         # nav + logout
├── api.ts                          # fetch wrapper, CSRF header from meta tag
├── pages/
│   ├── DashboardPage.vue           # at-a-glance counts
│   ├── MediaItemsPage.vue          # CRUD table + active toggle
│   ├── DevicesPage.vue             # fleet: online/offline, memory, uptime
│   └── IntegrationsPage.vue        # Google Workspace connect/sync — see below
└── components/
    ├── MediaItemFormDialog.vue     # native <dialog>, create/edit
    └── StatusBadge.vue
```

```
backend/app/Http/Controllers/Admin/
├── MediaItemController.php         # full CRUD, unfiltered (unlike the kiosk's onAir() scope)
├── DeviceController.php            # fleet list + latest heartbeat, eager-loaded (no N+1)
└── GoogleIntegrationController.php # OAuth connect/callback + sync — see below
```

**Why this is architecturally the mirror image of the kiosk player, on
purpose**: the player is a single, unauthenticated, cross-origin, 24/7
embedded view optimized to do as little work as possible. The admin panel
is an ordinary, session-authenticated, same-origin, human-attended desktop
app — so it uses `vue-router` (four real pages), plain `ref`/`reactive`
(no `shallowRef`/`markRaw`; there's no proxy-overhead budget to protect
here), and Tailwind for styling. Neither choice is a default — each is
what its constraints actually call for. Auth is plain Laravel session +
CSRF (`AuthController`, `routes/web.php`), not Sanctum SPA tokens or a
separate package, because the admin panel is served by this same Laravel
app and never leaves its origin.

Sign in at `/admin/login` with the seeded user (`test@example.com` /
`password`).

## 4. Google Workspace Integration — pulling a ticker from a Google Doc

The signage-specific case for this: a facilities/comms team already
writes the day's announcement somewhere — a Doc is a much lower-friction
source of truth than asking them to also log into a signage CMS. This
integration is the "third party integrations (Google Workspace/Microsoft
365)" capability made concrete on one real example, not a stub — it's a
full OAuth 2.0 grant, a token refresh cycle, and a scheduled sync,
end-to-end.

```
backend/app/Services/Google/
├── GoogleOAuthClient.php        # authorize URL, code exchange, refresh — raw HTTP, no SDK
├── GoogleDocsClient.php         # fetches a Doc, extracts paragraph text from its JSON structure
└── GoogleAnnouncementSyncer.php # orchestrates: refresh token → fetch doc → upsert one ticker MediaItem

backend/app/Console/Commands/SyncGoogleAnnouncement.php  # signage:sync-google-announcement
backend/app/Models/GoogleIntegration.php                 # singleton row: tokens, document_id, last_synced_at/last_error
```

**No `google/apiclient`.** That SDK wraps every Google API Google ships;
this integration uses exactly two endpoints (OAuth token exchange, Docs
`documents.get`), so pulling in the whole client library would hide the
actual protocol behind generated code for no benefit. `GoogleOAuthClient`
talks to `accounts.google.com`/`oauth2.googleapis.com` directly via
Laravel's `Http` facade — visible, and trivially mockable with
`Http::fake()` in tests (see `tests/Feature/GoogleIntegrationTest.php`).

**The flow**: an admin clicks "Connect Google Workspace" on
`/admin/integrations` → full-page redirect to Google's consent screen
(`access_type=offline&prompt=consent`, specifically so a refresh token
comes back even on a re-connect) → Google redirects back to
`/admin/integrations/google/callback` with a code → the controller
exchanges it for an access + refresh token, encrypted at rest via
Eloquent's `encrypted` cast. From there, `signage:sync-google-announcement`
runs every 15 minutes (`routes/console.php`): refresh the access token if
expired, fetch the configured Doc, extract its paragraph text, and
upsert the one `MediaItem` (type `ticker`) this integration owns —
which, like any other `MediaItem` write, fires `ScheduleUpdated` and
reaches every screen within seconds. The same sync path is exposed as a
"Sync now" button for testing without waiting on the schedule.

**Scoped to read-only, on purpose**: the OAuth request asks for exactly
`documents.readonly`, not Drive access or write scopes — the integration
only ever reads one Doc's text, and Google's consent screen shows the
admin exactly that, not a blanket "manage your Drive" grant that would
be true of a wider scope this integration doesn't need.

**Verified against the real Google API, not just mocks.** With a
deliberately invalid token, a manual "Sync now" reached
`docs.googleapis.com` for real and got Google's actual `401
UNAUTHENTICATED` response back — round-tripped correctly through the
sync error handling into `last_error` and the admin UI. What's *not*
verified end-to-end in this environment is the OAuth consent screen
itself: that requires a real Google Cloud OAuth client (`GOOGLE_CLIENT_ID`
/ `GOOGLE_CLIENT_SECRET` in `backend/.env`, with the callback URL added to
the client's redirect URI allow-list), which this sandbox has no way to
provision. The code path it would exercise — `exchangeCodeForTokens` —
is covered instead by a feature test that fakes Google's token endpoint
response and asserts the tokens land correctly on the `GoogleIntegration`
row.

## Running it locally

```bash
# Backend
cd backend
composer install
npm install                       # admin dashboard's own Vite build
cp .env.example .env && php artisan key:generate
# fill in REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET (any random values)
php artisan migrate --seed
php artisan serve                 # terminal 1
php artisan reverb:start          # terminal 2 — needs Redis running locally
npm run dev                       # terminal 3 — admin dashboard assets (Vite HMR)

# Frontend (kiosk player — separate app, separate origin)
cd frontend
npm install
cp .env.example .env              # VITE_REVERB_APP_KEY must match backend's REVERB_APP_KEY
npm run dev                       # terminal 4
```

Open the kiosk player at the frontend's printed Vite URL — it boots, fetches
the seeded playlist (a ticker, four slides, one video), and rotates through
it. Open the admin dashboard at `http://localhost:8000/admin` (sign in with
`test@example.com` / `password`) and edit a `MediaItem` — every open player
updates within seconds via the WebSocket push; kill Reverb and it keeps
updating every 30 seconds via the poll instead.

To try the Google Workspace integration itself (not just its tests),
create an OAuth 2.0 Client in a Google Cloud project (APIs & Services →
Credentials → "Web application"), add
`http://localhost:8000/admin/integrations/google/callback` to its
authorized redirect URIs, and put its client id/secret in
`backend/.env` as `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET`. Without
that, `/admin/integrations` still renders correctly ("Not connected") —
it's only the OAuth grant itself that needs real credentials.

## Running the backend with Docker

```bash
cp backend/.env.docker.example backend/.env.docker
# fill in APP_KEY (php -r "echo 'base64:'.base64_encode(random_bytes(32));"),
# REVERB_APP_KEY, and REVERB_APP_SECRET (any random values, must match
# whatever VITE_REVERB_APP_KEY the frontend/admin points at)

docker compose up -d --build
docker compose exec web php artisan db:seed --force   # optional: demo content
```

The API is at `http://localhost:8090`, the admin dashboard at
`http://localhost:8090/admin`, and Reverb's WebSocket at
`ws://localhost:6001`.

**Five services, one image.** `web` (nginx + php-fpm in a single
container — see `docker/start-web.sh` for why splitting them wasn't worth
it here), `reverb`, and `queue` are all the *same* built image
(`backend/Dockerfile`), just started with a different `command:`. A
dedicated one-shot `migrate` service runs the migrations exactly once and
gates the other three behind `condition: service_completed_successfully`
— letting each of them run `migrate --force` on their own start would
have three containers racing to create the same table against a cold
database. That's not a hypothetical: it's the first thing that broke
when this was actually tested, alongside a PHP version mismatch against
`composer.lock`, a missing `pcntl` extension (Reverb won't start without
it), and a stale host-generated `bootstrap/cache/packages.php` getting
baked into the image and shipping a reference to a dev-only package. All
four are exactly the class of bug that only surfaces by actually running
`docker compose up` — which is why this was.

## Tests

- `backend/tests/Feature/DisplayScheduleTest.php` — the on-air filter
  (active flag + daypart window), the trimmed kiosk JSON shape, and the
  ETag/304 path specifically: a matching `If-None-Match` gets a 304 with
  no body, a stale one gets a fresh 200 with a new ETag.
- `backend/tests/Feature/HeartbeatTest.php` — validation (missing
  `device_uid`, non-numeric memory/uptime, an out-of-enum `status`), and
  that repeated heartbeats from the same `device_uid` upsert one
  `PlayerDevice` row while still appending a new `PlayerHeartbeat` each
  time — the exact append-only shape the fleet dashboard's memory-creep
  detection depends on.
- `backend/tests/Feature/ScheduleBroadcastTest.php` — `MediaItem`
  create/update/delete each dispatch `ScheduleUpdated`, and its
  broadcast payload reflects only the current on-air set.
- `frontend/src/composables/__tests__/useSlideRotation.spec.ts` — the
  rotation timer, using Vitest's fake timers: auto-advance after
  `duration`, videos never auto-advancing (only `advance()` moves past
  one), the index clamping back into range when the schedule shrinks
  mid-rotation, and the pending timer actually clearing on cleanup.
  Writing this test is what surfaced a real bug in the composable
  itself: `onUnmounted` silently no-ops outside a component instance,
  so `useSlideRotation` now cleans up via `onScopeDispose` instead —
  identical behavior inside a real component, but (unlike `onUnmounted`)
  actually testable, and correct for a composable that shouldn't have to
  assume it's always called from one.

Coverage stops well short of exhaustive — the admin CRUD endpoints, the
other composables, and the Service Worker's caching strategies have no
tests yet. What's here targets the parts with the most non-obvious
behavior to protect (ETag negotiation, the device-upsert shape, a timer
racing a DOM event) rather than padding a coverage number.

## CI — `.github/workflows/ci.yml`

Four independent jobs, run in parallel on every push/PR:

- **Backend (PHP)** — `composer install`, `php artisan test`
  (phpunit.xml already points the test environment at in-memory SQLite
  and the sync queue, so no MySQL/Redis service containers are needed
  just to run the suite), `vendor/bin/pint --test`.
- **Admin dashboard** — `vue-tsc --build` + `vite build`, catching a
  broken admin build before it reaches the Laravel app that serves it.
- **Kiosk player** — `vue-tsc --build`, `vitest run`, `vite build`.
- **Docker image build** — builds `backend/Dockerfile`'s `runtime`
  target (no push). This is what would have caught every issue listed
  above in a PR, before it ever reached a real deploy.

## What's intentionally out of scope

This is a PoC demonstrating the player/broadcast/admin core, not a full
CMS. No per-device Sanctum authentication for the kiosk API (noted inline
in `routes/channels.php` and `StoreHeartbeatRequest` as the production next
step — today any device can POST a heartbeat or read the schedule), no
multi-user roles/permissions in the admin panel (one seeded user), no
pagination on the admin tables (fine at demo scale, not at fleet scale),
no Microsoft 365 equivalent of the Google Doc sync (the same
`GoogleAnnouncementSyncer` shape — OAuth client, a "fetch + extract text"
client, an orchestrator — would carry over directly to Microsoft Graph
against a OneNote page or Word doc). Each is a straightforward extension
of what's already in place.
