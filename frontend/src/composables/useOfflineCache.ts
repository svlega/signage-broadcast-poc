import { markRaw } from 'vue'
import type { MediaItem } from '@/types/media'

/**
 * Offline resilience, part 1 of 2 (part 2 is the Service Worker in
 * public/service-worker.js, which caches the actual media bytes).
 *
 * WHY IndexedDB and not localStorage:
 * - localStorage is synchronous and blocks the main JS thread on every
 *   read/write. On a signage box that's also decoding video and running
 *   rotation timers, a blocking call is a dropped frame. IndexedDB is
 *   async by design.
 * - localStorage has an effective ~5MB ceiling and stores strings only,
 *   forcing JSON.stringify/parse of the whole blob on every access.
 *   IndexedDB stores structured-cloned objects directly and scales to
 *   tens of MB, comfortably fitting a full day's schedule metadata.
 *
 * WHY not a library (idb-keyval, Dexie, etc.):
 * A signage player boots once and runs for weeks — the cost of a
 * dependency is paid once at boot, not per-frame, so this isn't a hard
 * rule. But the actual surface area needed here — "save one JSON blob,
 * read it back" — is ~40 lines of native IndexedDB. Pulling in a
 * library (however small) for that trades a few KB of parse/boot time
 * for zero real benefit, so this composable talks to IndexedDB directly.
 */

const DB_NAME = 'signage-player'
const DB_VERSION = 1
const STORE_NAME = 'schedule-cache'
const CACHE_KEY = 'current-schedule'

interface CachedSchedule {
  items: MediaItem[]
  cachedAt: string
}

function openDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      const db = request.result
      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME)
      }
    }

    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

export function useOfflineCache() {
  /**
   * Persists the last-known-good schedule. Called after every successful
   * network fetch so the most recent state is always what a disconnected
   * boot falls back to — never a stale hardcoded default.
   */
  async function saveSchedule(items: MediaItem[]): Promise<void> {
    const db = await openDatabase()

    await new Promise<void>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite')
      const payload: CachedSchedule = { items, cachedAt: new Date().toISOString() }
      tx.objectStore(STORE_NAME).put(payload, CACHE_KEY)
      tx.oncomplete = () => resolve()
      tx.onerror = () => reject(tx.error)
    })

    db.close()
  }

  /**
   * Reads the cached schedule back. `markRaw` matters here: these
   * objects are about to be handed to a `shallowRef` in
   * useDisplaySchedule, and without markRaw, Vue would still recursively
   * wrap every nested property the first time it's *read* inside a
   * template — markRaw stops that at the source.
   */
  async function loadSchedule(): Promise<MediaItem[] | null> {
    const db = await openDatabase()

    const cached = await new Promise<CachedSchedule | undefined>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readonly')
      const req = tx.objectStore(STORE_NAME).get(CACHE_KEY)
      req.onsuccess = () => resolve(req.result)
      req.onerror = () => reject(req.error)
    })

    db.close()

    if (!cached) return null

    return cached.items.map((item) => markRaw(item))
  }

  return { saveSchedule, loadSchedule }
}
