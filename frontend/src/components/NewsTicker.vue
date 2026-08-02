<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ text: string }>()

// Scroll speed constant (px/sec) rather than a fixed animation duration,
// so a long announcement doesn't fly past at the same speed a short one
// crawls at — readability stays consistent regardless of message length.
const PIXELS_PER_SECOND = 120
const AVG_CHAR_PX = 14

const durationSeconds = computed(() => {
  const estimatedWidth = props.text.length * AVG_CHAR_PX
  return Math.max(estimatedWidth / PIXELS_PER_SECOND, 6)
})
</script>

<template>
  <div class="ticker-viewport">
    <div
      class="ticker-track"
      :style="{ animationDuration: `${durationSeconds}s` }"
    >
      {{ text }}
    </div>
  </div>
</template>

<style scoped>
.ticker-viewport {
  overflow: hidden;
  width: 100%;
  background: #b91c1c;
  color: #fff;
  padding: 0.75rem 0;
}

.ticker-track {
  display: inline-block;
  white-space: nowrap;
  font-size: 1.75rem;
  font-weight: 600;
  padding-left: 100%;

  /*
   * Hardware acceleration, deliberately:
   * - `translate3d` (not `translateX`) forces this element onto its own
   *   GPU compositor layer, same as it would for a real 3D transform.
   *   A plain 2D `translateX` on some SoC GPUs still gets composited on
   *   the main thread; the 3d form is the reliable way to force
   *   GPU compositing across the Android/ChromeOS Chromium builds this
   *   targets.
   * - `will-change: transform` tells the compositor to promote this
   *   layer *ahead of time*, so the first animation frame doesn't pay a
   *   layer-promotion cost that would otherwise show up as a visible
   *   stutter the moment the ticker starts scrolling.
   * - Animating `transform` (not `left`/`margin`) means this animation
   *   never triggers layout or paint on the main thread — it runs
   *   entirely on the compositor thread, so it keeps scrolling smoothly
   *   even while the JS thread is busy parsing a schedule poll response
   *   or handling a WebSocket message.
   * - This is a CSS animation, not a requestAnimationFrame loop: zero JS
   *   execution per frame, and the browser can throttle/pause it
   *   automatically if the tab is ever backgrounded.
   */
  transform: translate3d(0, 0, 0);
  will-change: transform;
  animation-name: ticker-scroll;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}

@keyframes ticker-scroll {
  from {
    transform: translate3d(0, 0, 0);
  }
  to {
    transform: translate3d(-100%, 0, 0);
  }
}
</style>
