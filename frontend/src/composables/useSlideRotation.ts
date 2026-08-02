import { shallowRef, computed, watch, onScopeDispose, type ShallowRef } from 'vue'
import type { MediaItem } from '@/types/media'

/**
 * Advances through the on-air playlist. Slides/tickers advance on their
 * own `duration`; videos advance on their native `ended` event instead
 * (see SignagePlayer.vue's <video @ended>) so playback speed changes or
 * buffering never desyncs the timer from what's actually on screen.
 */
export function useSlideRotation(schedule: ShallowRef<MediaItem[]>) {
  const currentIndex = shallowRef(0)
  let timer: ReturnType<typeof setTimeout> | null = null

  const currentItem = computed<MediaItem | null>(() => schedule.value[currentIndex.value] ?? null)

  function clearTimer() {
    if (timer) {
      clearTimeout(timer)
      timer = null
    }
  }

  function advance() {
    clearTimer()
    if (schedule.value.length === 0) return
    currentIndex.value = (currentIndex.value + 1) % schedule.value.length
  }

  function armTimerForCurrentItem() {
    clearTimer()
    const item = currentItem.value
    // Videos drive their own advance via the `ended` DOM event —
    // arming a duplicate timer here would race the two and could skip
    // or double-advance a slide.
    if (!item || item.type === 'video') return

    timer = setTimeout(advance, Math.max(item.duration, 1) * 1000)
  }

  // Re-arm whenever the current slide changes, and also when the
  // schedule itself is swapped out from under an in-progress rotation
  // (a live push arrived mid-loop) — guard the index back into range
  // rather than pointing at `undefined`.
  watch(
    [currentIndex, schedule],
    () => {
      if (currentIndex.value >= schedule.value.length) currentIndex.value = 0
      armTimerForCurrentItem()
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
