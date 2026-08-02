import { shallowRef, markRaw, onUnmounted } from 'vue'
import type { MediaItem, ScheduleResponse } from '@/types/media'
import { useOfflineCache } from '@/composables/useOfflineCache'

const API_BASE = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000'

/**
 * Owns the on-air playlist and how it's kept fresh: an initial fetch,
 * then a low-frequency poll as a resilience fallback underneath the
 * WebSocket push (see useSignageSocket) that normally makes the poll
 * redundant. Two transports for the same data isn't waste — it's what
 * keeps a screen updating even if Reverb is unreachable from this
 * network but plain HTTPS isn't.
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

  const { saveSchedule, loadSchedule } = useOfflineCache()

  let etag: string | null = null
  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let inFlight = false

  async function fetchSchedule(): Promise<void> {
    // A slow/hung network shouldn't stack up duplicate requests every
    // time the poll timer fires — the timer is rescheduled from the
    // fetch's own completion, but this guard covers the WebSocket
    // handler and the timer firing back-to-back.
    if (inFlight) return
    inFlight = true

    try {
      const headers: HeadersInit = etag ? { 'If-None-Match': etag } : {}
      const response = await fetch(`${API_BASE}/api/v1/display-schedule`, { headers })

      if (response.status === 304) {
        isOffline.value = false
        lastError.value = null
        return
      }

      if (!response.ok) throw new Error(`Schedule fetch failed: ${response.status}`)

      const nextEtag = response.headers.get('ETag')
      if (nextEtag) etag = nextEtag

      const body: ScheduleResponse = await response.json()

      // markRaw per-item: these are plain data objects rendered
      // read-only by the template. Without markRaw, the first template
      // access would still trigger Vue's reactive-proxy wrapping even
      // though nothing ever mutates them afterward.
      const items = body.data.map((item) => markRaw(item))
      schedule.value = items
      isOffline.value = false
      lastError.value = null

      // Fire-and-forget: the next boot's offline fallback shouldn't
      // block this boot's render.
      void saveSchedule(items)
    } catch (error) {
      lastError.value = error instanceof Error ? error.message : 'Unknown fetch error'

      // Only fall back to disk if we don't already have something on
      // screen — an active playlist should keep playing through a
      // transient network blip rather than being replaced by a
      // (possibly older) cached copy.
      if (schedule.value.length === 0) {
        const cached = await loadSchedule()
        if (cached) {
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
      await fetchSchedule()
      scheduleNextPoll(delaySeconds)
    }, delaySeconds * 1000)
  }

  async function start() {
    await fetchSchedule()
    scheduleNextPoll()
  }

  /**
   * Applied when useSignageSocket receives a live push over Reverb.
   * Goes through the same markRaw + shallowRef swap + disk-cache path
   * as a normal poll response, so "updated via WebSocket" and "updated
   * via HTTP poll" are indistinguishable to the rest of the app.
   */
  function applyLiveUpdate(items: MediaItem[]) {
    const rawItems = items.map((item) => markRaw(item))
    schedule.value = rawItems
    isOffline.value = false
    void saveSchedule(rawItems)
  }

  /**
   * Memory-leak prevention: a dangling setTimeout chain is the single
   * easiest way to slowly wind up a 24/7 tab — each fire reschedules
   * itself, so without an explicit stop the chain outlives the
   * component that created it. Composables invoked from setup() may
   * register their own onUnmounted, keeping the cleanup next to the
   * resource that needs it instead of duplicated in every caller.
   */
  onUnmounted(() => {
    if (pollTimer) clearTimeout(pollTimer)
  })

  return { schedule, isOffline, lastError, start, refetch: fetchSchedule, applyLiveUpdate }
}
