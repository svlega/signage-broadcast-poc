<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { api } from '@admin/api'
import type { Device, MediaItem } from '@admin/types'

const mediaItems = ref<MediaItem[]>([])
const devices = ref<Device[]>([])
const loading = ref(true)

onMounted(async () => {
  ;[mediaItems.value, devices.value] = await Promise.all([api.mediaItems.list(), api.devices.list()])
  loading.value = false
})

const activeCount = computed(() => mediaItems.value.filter((i) => i.is_active).length)
const onlineCount = computed(() => devices.value.filter((d) => d.is_online).length)
const degradedCount = computed(
  () => devices.value.filter((d) => d.is_online && d.latest_heartbeat?.status !== 'healthy').length,
)
</script>

<template>
  <div class="space-y-6">
    <h1 class="text-xl font-semibold">Dashboard</h1>

    <p v-if="loading" class="text-sm text-slate-400">Loading…</p>

    <div v-else class="grid grid-cols-3 gap-4">
      <div class="rounded-lg border border-slate-800 p-4">
        <p class="text-sm text-slate-400">On-air media items</p>
        <p class="mt-1 text-2xl font-semibold">{{ activeCount }} <span class="text-sm font-normal text-slate-500">/ {{ mediaItems.length }}</span></p>
      </div>
      <div class="rounded-lg border border-slate-800 p-4">
        <p class="text-sm text-slate-400">Devices online</p>
        <p class="mt-1 text-2xl font-semibold">{{ onlineCount }} <span class="text-sm font-normal text-slate-500">/ {{ devices.length }}</span></p>
      </div>
      <div class="rounded-lg border border-slate-800 p-4">
        <p class="text-sm text-slate-400">Degraded / critical</p>
        <p class="mt-1 text-2xl font-semibold" :class="degradedCount > 0 ? 'text-amber-400' : ''">{{ degradedCount }}</p>
      </div>
    </div>

    <div class="flex gap-3 text-sm">
      <RouterLink to="/media-items" class="text-indigo-400 hover:text-indigo-300">Manage media items →</RouterLink>
      <RouterLink to="/devices" class="text-indigo-400 hover:text-indigo-300">View fleet →</RouterLink>
    </div>
  </div>
</template>
