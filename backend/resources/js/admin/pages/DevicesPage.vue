<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue'
import type { Device } from '@admin/types'
import { api } from '@admin/api'
import StatusBadge from '@admin/components/StatusBadge.vue'

const devices = ref<Device[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

async function load() {
  try {
    devices.value = await api.devices.list()
    error.value = null
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load devices'
  } finally {
    loading.value = false
  }
}

// A fleet view is exactly the kind of screen where a human actually is
// watching, unlike the kiosk player it's monitoring — polling every 15s
// here is a normal "keep this dashboard fresh" pattern, not something
// that needs the player's WebSocket-first design.
const REFRESH_INTERVAL_MS = 15_000
let timer: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  load()
  timer = setInterval(load, REFRESH_INTERVAL_MS)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})

function statusVariant(device: Device): 'success' | 'warning' | 'danger' | 'neutral' {
  if (!device.is_online) return 'neutral'
  switch (device.latest_heartbeat?.status) {
    case 'critical':
      return 'danger'
    case 'degraded':
      return 'warning'
    default:
      return 'success'
  }
}

function formatUptime(seconds: number): string {
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  if (hours >= 24) return `${Math.floor(hours / 24)}d ${hours % 24}h`
  if (hours > 0) return `${hours}h ${minutes}m`
  return `${minutes}m`
}
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-xl font-semibold">Devices</h1>

    <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
    <p v-else-if="loading" class="text-sm text-slate-400">Loading…</p>

    <table v-else class="w-full text-left text-sm">
      <thead class="text-slate-400">
        <tr class="border-b border-slate-800">
          <th class="py-2 font-medium">Device</th>
          <th class="py-2 font-medium">Location</th>
          <th class="py-2 font-medium">Status</th>
          <th class="py-2 font-medium">Memory</th>
          <th class="py-2 font-medium">Uptime</th>
          <th class="py-2 font-medium">Last seen</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="device in devices" :key="device.id" class="border-b border-slate-900">
          <td class="py-2">{{ device.name ?? device.device_uid }}</td>
          <td class="py-2 text-slate-400">{{ device.location ?? '—' }}</td>
          <td class="py-2">
            <StatusBadge
              :variant="statusVariant(device)"
              :text="device.is_online ? (device.latest_heartbeat?.status ?? 'online') : 'offline'"
            />
          </td>
          <td class="py-2 text-slate-400">
            <template v-if="device.latest_heartbeat">
              {{ device.latest_heartbeat.memory_used_mb }}<span v-if="device.latest_heartbeat.memory_limit_mb">/{{ device.latest_heartbeat.memory_limit_mb }}</span> MB
            </template>
            <template v-else>—</template>
          </td>
          <td class="py-2 text-slate-400">
            {{ device.latest_heartbeat ? formatUptime(device.latest_heartbeat.uptime_seconds) : '—' }}
          </td>
          <td class="py-2 text-slate-400">
            {{ device.last_seen_at ? new Date(device.last_seen_at).toLocaleString() : 'never' }}
          </td>
        </tr>
        <tr v-if="devices.length === 0">
          <td colspan="6" class="py-6 text-center text-slate-500">
            No devices have reported a heartbeat yet.
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
