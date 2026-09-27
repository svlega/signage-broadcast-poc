import { shallowRef, computed, watch, onScopeDispose, type ShallowRef } from 'vue'
import type { MediaItem } from '@/types/media'

/**
 * Advances through the on-air playlist. Slides/tickers advance on their
 * own `duration`; videos advance on their native `ended` event instead
 * (see SignagePlayer.vue's <video @ended>) so playback speed changes or
 * buffering never desyncs the timer from what's actually on screen.
 *
 * `currentItem` is a pinned snapshot, not a live read of
 * `schedule.value[currentIndex.value]`: a sync or WebSocket push can
 * update `schedule` — including the very item on screen — at any
 * moment, completely independent of the rotation timer. Re-deriving
 * `currentItem` from `schedule` on every such change would mutate the
 * displayed slide's image/video mid-display, or yank it off screen
 * entirely if it was deleted. Pinning means an update to the on-screen
 * item still lands in `schedule` immediately (any *other* item's update
 * is visible right away, since nothing else reads through the pin), but
 * the viewer keeps watching the frozen copy until `advance()` — the
 * only place the pin is ever re-sampled — next fires and picks up
 * whatever `schedule` now says, naturally re-synced with no special-case
 * bookkeeping for the update-vs-delete distinction.
 */
export function useSlideRotation(schedule: ShallowRef<MediaItem[]>) {
  const currentIndex = shallowRef(0)
  const pinnedItem = shallowRef<MediaItem | null>(null)
  let timer: ReturnType<typeof setTimeout> | null = null

  const currentItem = computed<MediaItem | null>(() => pinnedItem.value)

  function clearTimer() {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
  }

  function pinCurrentItem() {
    pinnedItem.value = schedule.value[currentIndex.value] ?? null
  }

  function armTimerForCurrentItem() {
    clearTimer()
    const item = pinnedItem.value
    // Videos drive their own advance via the `ended` DOM event —
    // arming a duplicate timer here would race the two and could skip
    // or double-advance a slide.
    if (!item || item.type === 'video') return

    timer = setTimeout(advance, Math.max(item.duration, 1) * 1000)
  }

  function advance() {
    clearTimer()
    if (schedule.value.length === 0) {
      pinnedItem.value = null
      return
    }
    currentIndex.value = (currentIndex.value + 1) % schedule.value.length
    pinCurrentItem()
    armTimerForCurrentItem()
  }

  // Pins (and arms) only on first activation — going from no content to
  // some. Every later change to `schedule` is deliberately left alone
  // here: re-pinning on every change is exactly the mid-display mutation
  // this composable exists to prevent. If the pinned item's slot
  // disappears entirely (e.g. deleted while on screen), it keeps
  // rendering its last-known content — stale but stable — until
  // advance() moves on.
  watch(
    schedule,
    () => {
      if (pinnedItem.value === null && schedule.value.length > 0) {
        pinCurrentItem()
        armTimerForCurrentItem()
      }
    },
    { immediate: true },
  )

  // onScopeDispose, not onUnmounted: the latter is a component-lifecycle
  // hook that silently no-ops (with a dev warning) unless it's called
  // during a component's setup(). onScopeDispose fires on *any* effect
  // scope teardown — a component unmounting stops its own internal
  // scope, so this still covers the normal case, but it also makes this
  // composable's cleanup correctly testable via a bare `effectScope()`
  // with no component involved at all.
  onScopeDispose(clearTimer)

  return { currentItem, currentIndex, advance }
}
