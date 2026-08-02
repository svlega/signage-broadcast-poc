<script setup lang="ts">
import { ref, shallowRef, computed, watch, onUnmounted, markRaw } from 'vue'
import { useDisplaySchedule } from '@/composables/useDisplaySchedule'
import { useSlideRotation } from '@/composables/useSlideRotation'
import { useSignageSocket } from '@/composables/useSignageSocket'
import { useHeartbeat } from '@/composables/useHeartbeat'
import NewsTicker from '@/components/NewsTicker.vue'

/**
 * Root component for the kiosk view. Composition, not choreography: every
 * piece of runtime behavior (fetching, rotation, sockets, health
 * reporting) lives in its own composable with its own cleanup, so this
 * component's job is just wiring them together and rendering whatever
 * `currentItem` currently is.
 */

const { schedule, isOffline, start, applyLiveUpdate } = useDisplaySchedule()
const { currentItem, currentIndex, advance } = useSlideRotation(schedule)
const { status: socketStatus } = useSignageSocket((items) => applyLiveUpdate(items))

// Heartbeat has no return value the template needs — it only needs to
// be invoked so its interval + onUnmounted cleanup get registered.
useHeartbeat()

void start()

const videoEl = ref<HTMLVideoElement | null>(null)

// A small bounded preload cache: the *next* slide's image is fetched
// ahead of time so it's already decoded by the time it becomes current,
// avoiding a blank flash. Capped at 3 entries so a long-running loop
// through a large playlist can't accumulate an unbounded number of
// decoded bitmaps in memory.
const MAX_PRELOAD_ENTRIES = 3
const preloadCache = shallowRef(new Map<string, HTMLImageElement>())

function preloadUpcomingSlide() {
  if (schedule.value.length === 0) return
  const upcoming = schedule.value[(currentIndex.value + 1) % schedule.value.length]
  if (!upcoming || upcoming.type !== 'slide' || !upcoming.url) return
  if (preloadCache.value.has(upcoming.id)) return

  const img = new Image()
  img.src = upcoming.url

  // The Image instance is plain runtime state, never rendered by Vue's
  // template, and never mutated reactively — markRaw stops Vue from
  // wrapping it (and its internal DOM properties) in a reactive proxy
  // the moment it's read out of the Map.
  preloadCache.value.set(upcoming.id, markRaw(img))

  if (preloadCache.value.size > MAX_PRELOAD_ENTRIES) {
    const oldestKey = preloadCache.value.keys().next().value
    if (oldestKey) {
      preloadCache.value.get(oldestKey)!.src = '' // release the decoded bitmap now, don't wait on GC
      preloadCache.value.delete(oldestKey)
    }
  }
}

watch(currentIndex, preloadUpcomingSlide, { immediate: true })

/**
 * Memory-leak prevention for video: when rotation moves off a video
 * item, explicitly pause + strip its src + call load(). Chromium can
 * hold onto hardware decoder buffers for a detached-but-not-yet-GC'd
 * <video> element; on a device with one shared hardware decoder, that's
 * the difference between the next video decoding in hardware or falling
 * back to (much more CPU-expensive) software decode.
 *
 * `flush: 'pre'` (Vue's default) runs this callback before the DOM
 * re-renders, so `videoEl.value` here still points at the *outgoing*
 * video element — after re-render it would already be a different node.
 */
watch(
  currentItem,
  (_next, previous) => {
    if (previous?.type === 'video' && videoEl.value) {
      videoEl.value.pause()
      videoEl.value.removeAttribute('src')
      videoEl.value.load()
    }
  },
  { flush: 'pre' },
)

onUnmounted(() => {
  for (const img of preloadCache.value.values()) {
    img.src = ''
  }
  preloadCache.value.clear()

  if (videoEl.value) {
    videoEl.value.pause()
    videoEl.value.removeAttribute('src')
    videoEl.value.load()
  }
})
</script>

<template>
  <div class="signage-player">
    <div v-if="isOffline" class="status-badge status-offline">OFFLINE — cached schedule</div>
    <div v-else-if="socketStatus !== 'connected'" class="status-badge status-reconnecting">
      Reconnecting live feed…
    </div>

    <!--
      mode="out-in": the outgoing media element is fully removed before
      the incoming one mounts, so there is never a moment with two
      <video>/<img> elements decoding simultaneously. A brief gap on
      transition beats doubling peak memory/decoder usage on every
      single slide change across a 24/7 loop.
    -->
    <Transition name="crossfade" mode="out-in">
      <video
        v-if="currentItem?.type === 'video'"
        :key="currentItem.id"
        ref="videoEl"
        class="media-layer"
        :src="currentItem.url ?? undefined"
        autoplay
        muted
        playsinline
        @ended="advance"
      />
      <img
        v-else-if="currentItem?.type === 'slide'"
        :key="currentItem.id"
        class="media-layer"
        :src="currentItem.url ?? undefined"
        :alt="currentItem.title"
      />
      <div v-else-if="currentItem?.type === 'ticker'" :key="currentItem.id" class="media-layer ticker-slot">
        <NewsTicker :text="currentItem.body ?? ''" />
      </div>
      <div v-else key="empty" class="media-layer empty-state">No content scheduled</div>
    </Transition>
  </div>
</template>

<style scoped>
.signage-player {
  position: relative;
  width: 100vw;
  height: 100vh;
  background: #000;
  overflow: hidden;
}

.media-layer {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.ticker-slot {
  display: flex;
  align-items: flex-end;
}

.empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #666;
  font-size: 1.5rem;
}

.status-badge {
  position: absolute;
  top: 1rem;
  right: 1rem;
  z-index: 10;
  padding: 0.4rem 0.9rem;
  border-radius: 999px;
  font-size: 0.85rem;
  font-weight: 600;
  color: #fff;
}

.status-offline {
  background: #92400e;
}

.status-reconnecting {
  background: #1e3a8a;
}

/*
 * Opacity-only crossfade: like the ticker's transform animation, this
 * runs on the compositor thread instead of triggering layout, and
 * `will-change` pre-promotes the layer so the very first transition
 * isn't the one that pays the promotion cost.
 */
.crossfade-enter-active,
.crossfade-leave-active {
  transition: opacity 0.4s ease;
  will-change: opacity;
}

.crossfade-enter-from,
.crossfade-leave-to {
  opacity: 0;
}
</style>
