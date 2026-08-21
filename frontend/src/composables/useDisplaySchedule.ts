import { shallowRef, markRaw, onScopeDispose } from 'vue'
import type { MediaItem, SyncResponse } from '@/types/media'
import { useContentManifest } from '@/composables/useContentManifest'

const API_BASE = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000'

/**
 * Owns the on-air playlist and how it's kept fresh: an initial sync,
 * then a low-frequency poll as a resilience fallback underneath the
 * WebSocket push (see useSignageSocket) that normally makes the poll
 * redundant. Two transports for the same data isn't waste — it's what
 * keeps a screen updating even if Reverb is unreachable from this
 * network but plain HTTPS isn't.
 *
 * "Sync," not "fetch": every poll asks the delta endpoint for only what
 * changed since this device's own last successful sync
 * (GET /display-schedule/sync?since=...), then reconciles that into the
 * local manifest (see useContentManifest) rather than replacing it
 * wholesale. That's what keeps a reconnect after an arbitrarily long
 * offline window cheap — the response size depends on how much actually
 * changed, never on how large the content library is — and it's the
 * whole reason the manifest is a real per-item store instead of a
 * cached copy of the last response.
 */
export function useDisplaySchedule() {
  // shallowRef, not ref: the array reference is swapped wholesale on
  // every update; Vue only needs to react to *that* swap. A deep `ref`
  // would recursively proxy every MediaItem (and every property on it)
  // just so we can mutate fields we never mutate — pure overhead on a
  // device with limited RAM to spend on framework bookkeeping.
  const schedule = shallowRef<MediaItem[]>([])
  const isOffline = shallowRef(false)
  const lastError = shallowRef<string | null>(null)

  const { replaceAll, applyDelta, loadAll, getLastSyncedAt, setLastSyncedAt } = useContentManifest()

  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let inFlight = false

  async function syncSchedule(): Promise<void> {
    // A slow/hung network shouldn't stack up duplicate requests every
    // time the poll timer fires — the timer is rescheduled from the
    // sync's own completion, but this guard covers the WebSocket
    // handler and the timer firing back-to-back.
    if (inFlight) return
    inFlight = true

    try {
      // No watermark yet (first boot, or a manifest that's never
      // synced) — omitting `since` entirely, not sending an empty
      // string, is what makes the server treat this as "everything
      // on-air is new" instead of a date it has to fail to parse.
      const since = await getLastSyncedAt()
      const url = new URL(`${API_BASE}/api/v1/display-schedule/sync`)
      if (since) url.searchParams.set('since', since)

      const response = await fetch(url)

      // The server's cheap fast path: it already knows, from `since`
      // alone, that nothing has happened anywhere since this device's
      // last sync, without running either delta query. No body means
      // nothing to parse or apply — and the existing watermark is still
      // exactly correct for the *next* poll too, so there's nothing to
      // advance here either.
      if (response.status === 304) {
        isOffline.value = false
        lastError.value = null

        // A 304 means "nothing changed on the server," not "nothing
        // exists" — but `schedule` starts empty on every fresh page load
        // (it's in-memory only), so a reload that lands on a 304 before
        // anything ever goes through applyDelta/applyLiveUpdate would
        // otherwise show "No content scheduled" forever despite the
        // manifest already holding the full playlist on disk.
        if (schedule.value.length === 0) {
          schedule.value = await loadAll()
        }
        return
      }

      if (!response.ok) throw new Error(`Schedule sync failed: ${response.status}`)

      const body: SyncResponse = await response.json()

      // A 200 can still carry an empty delta even past the 304 fast
      // path above — e.g. a write touched an item that isn't currently
      // on-air, which advances the server's cheap "anything changed at
      // all" check without actually affecting this device's on-air set.
      // Skip touching the manifest (and, critically, `schedule.value`)
      // on that empty-but-200 case too: `schedule` is a shallowRef, and
      // a watcher downstream (useSlideRotation) triggers on *reference*
      // reassignment, not content equality — handing back even an
      // identical array would reset the current slide's dwell timer for
      // no reason.
      if (body.updated.length > 0 || body.deleted.length > 0) {
        // The manifest, not this response, is the source of truth for
        // what renders: applyDelta upserts/removes exactly the rows this
        // response named and hands back the *reconciled* full set —
        // reading the on-screen state back out of durable storage instead
        // of assembling it from a transient network payload is what keeps
        // the reactive list and the offline cache from ever disagreeing.
        schedule.value = await applyDelta(body.updated, body.deleted)
      }
      isOffline.value = false
      lastError.value = null

      // The server's clock, never this device's — see
      // useContentManifest's own note on why that matters across an
      // offline gap of unknown length.
      await setLastSyncedAt(body.server_time)
    } catch (error) {
      lastError.value = error instanceof Error ? error.message : 'Unknown fetch error'

      // Only fall back to disk if we don't already have something on
      // screen — an active playlist should keep playing through a
      // transient network blip rather than being replaced by a
      // (possibly older) cached copy.
      if (schedule.value.length === 0) {
        const cached = await loadAll()
        if (cached.length > 0) {
          schedule.value = cached
          isOffline.value = true
        }
      }
    } finally {
      inFlight = false
    }
  }

  function scheduleNextPoll(delaySeconds = 30) {
    if (pollTimer) clearTimeout(pollTimer)
    pollTimer = setTimeout(async () => {
      await syncSchedule()
      scheduleNextPoll(delaySeconds)
    }, delaySeconds * 1000)
  }

  async function start() {
    await syncSchedule()
    scheduleNextPoll()
  }

  /**
   * Applied when useSignageSocket receives a live push over Reverb.
   * Unlike syncSchedule above, a broadcast already carries the complete
   * current on-air set (it's a push, not a poll-and-diff), so this
   * replaces the manifest wholesale rather than reconciling a delta —
   * there's nothing partial to reconcile. The broadcast's own
   * `updated_at` still advances the sync watermark, though: a device
   * that's kept its WebSocket connection through several live pushes
   * shouldn't have to re-fetch all of them again the next time its poll
   * timer happens to fire.
   */
  function applyLiveUpdate(items: MediaItem[], serverTime: string) {
    // markRaw here specifically: unlike syncSchedule's `merged` (already
    // raw-marked by applyDelta/loadAll on the way out of the manifest),
    // these arrive straight off the WebSocket as plain JSON-parsed
    // objects — this is the one path that reads render state from a
    // network payload instead of the manifest, so it's the one path
    // still responsible for marking it raw itself.
    const rawItems = items.map((item) => markRaw(item))
    schedule.value = rawItems
    isOffline.value = false
    void replaceAll(rawItems)
    void setLastSyncedAt(serverTime)
  }

  /**
   * Memory-leak prevention: a dangling setTimeout chain is the single
   * easiest way to slowly wind up a 24/7 tab — each fire reschedules
   * itself, so without an explicit stop the chain outlives the
   * component that created it. onScopeDispose, not onUnmounted: the
   * latter silently no-ops outside a real component instance, which
   * would make this composable's cleanup untestable in isolation (see
   * useSlideRotation.ts for where that gap was actually caught).
   * onScopeDispose fires on any effect scope teardown, including a
   * component unmounting — same production behavior, but correct to
   * call from a bare composable too.
   */
  onScopeDispose(() => {
    if (pollTimer) clearTimeout(pollTimer)
  })

  return { schedule, isOffline, lastError, start, refetch: syncSchedule, applyLiveUpdate }
}
