import { markRaw } from 'vue'
import type { MediaItem } from '@/types/media'

/**
 * Offline resilience, part 1 of 2 (part 2 is the Service Worker in
 * public/service-worker.js, which caches the actual media bytes).
 *
 * This is the client-side counterpart to the backend's delta-sync
 * endpoint (GET /api/v1/display-schedule/sync?since=...): a per-item
 * table — id, and whatever fields the player needs to render — not one
 * JSON blob, plus a small watermark of when this device last synced
 * successfully. Storing content this way is what makes a delta actually
 * *applicable*: `applyDelta` below only ever touches the handful of rows
 * a sync response names, upserting or removing them by id, instead of
 * needing to reconstruct the full set from scratch on every update.
 *
 * WHY IndexedDB and not localStorage: see useDisplaySchedule.ts's own
 * notes — unchanged reasoning, this is the same tradeoff.
 *
 * WHY not a library (idb-keyval, Dexie, etc.): the actual surface area
 * needed here is still well under 150 lines of native IndexedDB. A
 * library buys nothing a signage player needs to pay boot-time parse
 * cost for.
 */

const DB_NAME = 'signage-player'
// v1: one JSON blob under a single key. v2: a real per-item object
// store, but still only ever fully replaced. v3 adds `sync-state` — the
// `last_synced_at` watermark a delta sync needs to ask the server "what
// changed since I last checked," which has nowhere to live in a schema
// that's just the content rows themselves. onupgradeneeded drops the
// v1-era store on any real device still carrying it, so migrating never
// leaves dead data sitting in IndexedDB forever.
const DB_VERSION = 3
const STORE_NAME = 'content-manifest'
const SYNC_STATE_STORE = 'sync-state'
const LAST_SYNCED_KEY = 'last_synced_at'
const LEGACY_STORE_NAME = 'schedule-cache'

function openDatabase(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, DB_VERSION)

    request.onupgradeneeded = () => {
      const db = request.result

      if (db.objectStoreNames.contains(LEGACY_STORE_NAME)) {
        db.deleteObjectStore(LEGACY_STORE_NAME)
      }

      if (!db.objectStoreNames.contains(STORE_NAME)) {
        db.createObjectStore(STORE_NAME, { keyPath: 'id' })
      }

      if (!db.objectStoreNames.contains(SYNC_STATE_STORE)) {
        // Out-of-line keys, not a keyPath: this store holds one
        // unrelated scalar value (currently just the sync watermark),
        // not a collection of same-shaped records.
        db.createObjectStore(SYNC_STATE_STORE)
      }
    }

    request.onsuccess = () => resolve(request.result)
    request.onerror = () => reject(request.error)
  })
}

export function useContentManifest() {
  /**
   * Replaces the entire manifest with exactly the given set. Correct
   * for a source that always hands back a full snapshot rather than a
   * diff — the WebSocket push is the one remaining caller of this: a
   * broadcast carries the complete current on-air set by design, so
   * there's nothing to reconcile, only to swap in wholesale. `clear()`
   * and every `put()` run inside one readwrite transaction, so a crash
   * or tab close mid-write can't leave the manifest half-old, half-new.
   */
  async function replaceAll(items: MediaItem[]): Promise<void> {
    const db = await openDatabase()

    await new Promise<void>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite')
      const store = tx.objectStore(STORE_NAME)

      store.clear()
      for (const item of items) {
        store.put(item)
      }

      tx.oncomplete = () => resolve()
      tx.onerror = () => reject(tx.error)
    })

    db.close()
  }

  /**
   * Applies a delta-sync response: upserts every changed item, removes
   * every deleted id, leaves everything else untouched — the difference
   * between this and `replaceAll` is exactly the difference between a
   * full resync and an actual incremental sync. Both mutations run in
   * one transaction for the same reason `replaceAll`'s do: partial
   * application on a crash would leave the manifest inconsistent with
   * what the server thinks this device holds.
   *
   * Returns the reconciled, sorted manifest directly (a `loadAll()` the
   * caller would otherwise have to remember to make) so a sync always
   * ends with one source of truth for what's now on screen.
   */
  async function applyDelta(updated: MediaItem[], deletedIds: string[]): Promise<MediaItem[]> {
    const db = await openDatabase()

    await new Promise<void>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readwrite')
      const store = tx.objectStore(STORE_NAME)

      for (const item of updated) {
        store.put(item)
      }
      for (const id of deletedIds) {
        store.delete(id)
      }

      tx.oncomplete = () => resolve()
      tx.onerror = () => reject(tx.error)
    })

    db.close()

    return loadAll()
  }

  /**
   * Reads the whole manifest back, in on-air sort order. `markRaw` per
   * item matters here for the same reason it did in the old blob store:
   * these are about to be handed to a `shallowRef`, and without it, the
   * first template read would still trigger Vue's reactive-proxy
   * wrapping even though nothing ever mutates them afterward.
   */
  async function loadAll(): Promise<MediaItem[]> {
    const db = await openDatabase()

    const items = await new Promise<MediaItem[]>((resolve, reject) => {
      const tx = db.transaction(STORE_NAME, 'readonly')
      const req = tx.objectStore(STORE_NAME).getAll()
      req.onsuccess = () => resolve(req.result as MediaItem[])
      req.onerror = () => reject(req.error)
    })

    db.close()

    return items.sort((a, b) => a.order - b.order).map((item) => markRaw(item))
  }

  /**
   * The device's own watermark for "what have I already seen" — sent
   * back to the server as `?since=` on the next sync. Deliberately
   * always the *server's* clock (see setLastSyncedAt callers), never
   * this device's: comparing two different clocks across an offline gap
   * of unknown length is exactly the class of drift bug a server-issued
   * watermark sidesteps entirely.
   */
  async function getLastSyncedAt(): Promise<string | null> {
    const db = await openDatabase()

    const value = await new Promise<string | undefined>((resolve, reject) => {
      const tx = db.transaction(SYNC_STATE_STORE, 'readonly')
      const req = tx.objectStore(SYNC_STATE_STORE).get(LAST_SYNCED_KEY)
      req.onsuccess = () => resolve(req.result)
      req.onerror = () => reject(req.error)
    })

    db.close()

    return value ?? null
  }

  async function setLastSyncedAt(serverTime: string): Promise<void> {
    const db = await openDatabase()

    await new Promise<void>((resolve, reject) => {
      const tx = db.transaction(SYNC_STATE_STORE, 'readwrite')
      tx.objectStore(SYNC_STATE_STORE).put(serverTime, LAST_SYNCED_KEY)
      tx.oncomplete = () => resolve()
      tx.onerror = () => reject(tx.error)
    })

    db.close()
  }

  return { replaceAll, applyDelta, loadAll, getLastSyncedAt, setLastSyncedAt }
}
