<script setup lang="ts">
import { ref, onMounted } from 'vue'
import type { MediaItem } from '@admin/types'
import { api } from '@admin/api'
import StatusBadge from '@admin/components/StatusBadge.vue'
import MediaItemFormDialog from '@admin/components/MediaItemFormDialog.vue'

const items = ref<MediaItem[]>([])
const loading = ref(true)
const error = ref<string | null>(null)

const dialogRef = ref<InstanceType<typeof MediaItemFormDialog> | null>(null)

async function load() {
  loading.value = true
  error.value = null
  try {
    items.value = await api.mediaItems.list()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load media items'
  } finally {
    loading.value = false
  }
}

onMounted(load)

function openCreate() {
  dialogRef.value?.open()
}

function openEdit(item: MediaItem) {
  dialogRef.value?.open(item)
}

function onSaved(item: MediaItem) {
  const index = items.value.findIndex((i) => i.id === item.id)
  if (index === -1) {
    items.value = [...items.value, item].sort((a, b) => a.sort_order - b.sort_order)
  } else {
    items.value.splice(index, 1, item)
  }
}

async function toggleActive(item: MediaItem) {
  const updated = await api.mediaItems.update(item.id, { ...withoutMeta(item), is_active: !item.is_active })
  onSaved(updated)
}

async function remove(item: MediaItem) {
  if (!confirm(`Delete "${item.title}"? This can't be undone.`)) return
  await api.mediaItems.delete(item.id)
  items.value = items.value.filter((i) => i.id !== item.id)
}

function withoutMeta(item: MediaItem) {
  const { id: _id, uuid: _uuid, checksum: _checksum, updated_at: _updatedAt, ...rest } = item
  return rest
}
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-center justify-between">
      <h1 class="text-xl font-semibold">Media Items</h1>
      <button
        class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium hover:bg-indigo-500"
        @click="openCreate"
      >
        + New item
      </button>
    </div>

    <p v-if="error" class="text-sm text-red-400">{{ error }}</p>
    <p v-else-if="loading" class="text-sm text-slate-400">Loading…</p>

    <table v-else class="w-full text-left text-sm">
      <thead class="text-slate-400">
        <tr class="border-b border-slate-800">
          <th class="py-2 font-medium">Order</th>
          <th class="py-2 font-medium">Title</th>
          <th class="py-2 font-medium">Type</th>
          <th class="py-2 font-medium">Duration</th>
          <th class="py-2 font-medium">Status</th>
          <th class="py-2 font-medium"></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="item in items" :key="item.id" class="border-b border-slate-900">
          <td class="py-2 text-slate-400">{{ item.sort_order }}</td>
          <td class="py-2">{{ item.title }}</td>
          <td class="py-2 capitalize text-slate-400">{{ item.type }}</td>
          <td class="py-2 text-slate-400">{{ item.duration_seconds }}s</td>
          <td class="py-2">
            <button @click="toggleActive(item)">
              <StatusBadge
                :variant="item.is_active ? 'success' : 'neutral'"
                :text="item.is_active ? 'Active' : 'Inactive'"
              />
            </button>
          </td>
          <td class="space-x-3 py-2 text-right">
            <button class="text-slate-400 hover:text-slate-100" @click="openEdit(item)">Edit</button>
            <button class="text-red-400 hover:text-red-300" @click="remove(item)">Delete</button>
          </td>
        </tr>
        <tr v-if="items.length === 0">
          <td colspan="6" class="py-6 text-center text-slate-500">No media items yet.</td>
        </tr>
      </tbody>
    </table>

    <MediaItemFormDialog ref="dialogRef" @saved="onSaved" />
  </div>
</template>
