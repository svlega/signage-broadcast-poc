/**
 * Signage Player Service Worker
 *
 * Scope: cache the *bytes* of media (images/video) and the built app
 * shell (JS/CSS/HTML) so a screen that loses its network connection
 * keeps rendering the last known-good playlist instead of a broken
 * image icon or a blank tab. Structured schedule *metadata* is cached
 * separately, in IndexedDB, by useOfflineCache.ts — this worker never
 * touches that data.
 *
 * Deliberately hand-written instead of via a plugin (e.g. vite-plugin-pwa
 * / Workbox): the caching rules this player actually needs — three
 * strategies, no navigation preload, no background sync — are a small
 * enough surface that a ~100-line file is easier to reason about (and
 * lighter to parse/execute) than a general-purpose SW framework whose
 * features mostly go unused here.
 */

const CACHE_VERSION = 'v1'
const SHELL_CACHE = `signage-shell-${CACHE_VERSION}`
const MEDIA_CACHE = `signage-media-${CACHE_VERSION}`

const MEDIA_EXTENSIONS = /\.(png|jpe?g|gif|webp|svg|mp4|webm)$/i

self.addEventListener('install', (event) => {
  // A kiosk tab is, by design, never closed — the standard "new SW waits
  // until all tabs of the old one are closed" lifecycle would mean a
  // deployed update never actually takes effect on the device. Skipping
  // straight to activation is the correct tradeoff for hardware nobody
  // is going to reboot for you.
  event.waitUntil(self.skipWaiting())
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys()
      await Promise.all(
        keys
          .filter((key) => key !== SHELL_CACHE && key !== MEDIA_CACHE)
          .map((key) => caches.delete(key)),
      )
      await self.clients.claim()
    })(),
  )
})

self.addEventListener('fetch', (event) => {
  const { request } = event
  if (request.method !== 'GET') return

  const url = new URL(request.url)

  // Never intercept the API. useDisplaySchedule already has its own
  // offline fallback (IndexedDB) with explicit "isOffline" state the UI
  // can show — a Service Worker silently serving a stale cached JSON
  // response here would hide connectivity loss from that logic instead
  // of surfacing it.
  if (url.pathname.startsWith('/api/')) return

  if (MEDIA_EXTENSIONS.test(url.pathname)) {
    event.respondWith(cacheFirst(request, MEDIA_CACHE))
    return
  }

  if (request.mode === 'navigate' || url.origin === self.location.origin) {
    event.respondWith(staleWhileRevalidate(request, SHELL_CACHE))
  }
})

/**
 * Media rarely changes once published (the backend's `checksum` field
 * is how the player would notice a genuine change and re-fetch under a
 * new URL) — so once a slide/video is cached, prefer instant, offline
 * playback over a redundant network round-trip.
 */
async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName)
  const cached = await cache.match(request)
  if (cached) return cached

  try {
    const response = await fetch(request)
    if (response.ok) cache.put(request, response.clone())
    return response
  } catch (error) {
    // No cache and no network: let it fail — there is nothing left to
    // serve, and pretending otherwise would just surface a broken
    // asset later, harder to diagnose.
    throw error
  }
}

/**
 * The app shell should stay current the moment a new deploy is
 * reachable, but must never block on the network first — a screen
 * rebooting mid-outage should repaint immediately from cache while the
 * revalidation happens in the background for *next* time.
 */
async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName)
  const cached = await cache.match(request)

  const networkFetch = fetch(request)
    .then((response) => {
      if (response.ok) cache.put(request, response.clone())
      return response
    })
    .catch(() => cached)

  return cached ?? networkFetch
}
