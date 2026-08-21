import type { MediaItem } from '@/types/media'

/**
 * The Service Worker caches media cache-first, keyed by request URL
 * (see public/service-worker.js) — correct and cheap, but it means the
 * URL *is* the cache key. `checksum` (a hash of the item's content,
 * already flowing through the API and into the local manifest) exists
 * specifically to answer "did the underlying content actually change,"
 * but nothing consulted it before now: two edits to the same slide,
 * same URL, would have kept serving whatever bytes were cached from the
 * first one forever.
 *
 * Appending the checksum as a query param turns a content change into a
 * URL change: same checksum → same cache-busted URL → the Service
 * Worker's existing cache-first logic naturally reuses the cached
 * bytes; different checksum → different URL → guaranteed cache miss →
 * a fresh fetch, no changes to the Service Worker itself required. This
 * reuses the browser's own URL-keyed caching instead of writing custom
 * invalidation (a postMessage protocol to the worker, manual
 * caches.delete() calls, ...) to do the same job.
 */
export function cacheBustedMediaUrl(item: Pick<MediaItem, 'url' | 'checksum'>): string | null {
  if (!item.url) return null
  if (!item.checksum) return item.url

  const url = new URL(item.url)
  url.searchParams.set('v', item.checksum)
  return url.toString()
}
