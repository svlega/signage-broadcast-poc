<script setup lang="ts">
import { ref, reactive } from 'vue'
import type { MediaItem, MediaItemPayload } from '@admin/types'
import { api } from '@admin/api'

const emit = defineEmits<{ close: []; saved: [MediaItem] }>()

// Native <dialog>: modal semantics (focus trapping, Esc-to-close, a real
// ::backdrop) for free, with zero extra dependency — appropriate for an
// ordinary admin CRUD form where reaching for a headless-UI library
// would be solving a problem the platform already solves.
const dialogEl = ref<HTMLDialogElement | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)

// The item being edited, or null for "create". Deliberately NOT a prop:
// a prop set and then immediately read via an exposed open() method
// would race Vue's async prop-update flush — the parent's `ref` change
// doesn't reach this component's props until the next render, so
// reading `props.item` synchronously inside `open()` would still see
// the *previous* value. Taking the item as an open(item) argument
// sidesteps that timing entirely.
const editingItem = ref<MediaItem | null>(null)

const form = reactive<MediaItemPayload>(emptyForm())

function emptyForm(): MediaItemPayload {
  return {
    type: 'slide',
    title: '',
    url: '',
    body: '',
    duration_seconds: 10,
    sort_order: 0,
    starts_at: null,
    ends_at: null,
    is_active: true,
  }
}

function resetFromItem(source: MediaItem | null) {
  Object.assign(
    form,
    source
      ? {
          type: source.type,
          title: source.title,
          url: source.url ?? '',
          body: source.body ?? '',
          duration_seconds: source.duration_seconds,
          sort_order: source.sort_order,
          starts_at: source.starts_at,
          ends_at: source.ends_at,
          is_active: source.is_active,
        }
      : emptyForm(),
  )
}

defineExpose({
  open(item: MediaItem | null = null) {
    editingItem.value = item
    resetFromItem(item)
    error.value = null
    dialogEl.value?.showModal()
  },
})

function close() {
  dialogEl.value?.close()
  emit('close')
}

async function submit() {
  saving.value = true
  error.value = null

  try {
    const payload: MediaItemPayload = { ...form, url: form.url || null, body: form.body || null }
    const saved = editingItem.value
      ? await api.mediaItems.update(editingItem.value.id, payload)
      : await api.mediaItems.create(payload)
    emit('saved', saved)
    close()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to save'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <dialog
    ref="dialogEl"
    class="w-full max-w-md rounded-lg border border-slate-800 bg-slate-900 p-0 text-slate-100 backdrop:bg-black/60"
    @cancel="emit('close')"
  >
    <form class="space-y-4 p-6" @submit.prevent="submit">
      <h2 class="text-lg font-semibold">{{ editingItem ? 'Edit media item' : 'New media item' }}</h2>

      <div class="grid grid-cols-2 gap-3">
        <label class="col-span-2 text-sm">
          Title
          <input v-model="form.title" required class="mt-1 w-full rounded border border-slate-700 bg-slate-950 px-2 py-1.5" />
        </label>

        <label class="text-sm">
          Type
          <select v-model="form.type" class="mt-1 w-full rounded border border-slate-700 bg-slate-950 px-2 py-1.5">
            <option value="slide">Slide</option>
            <option value="video">Video</option>
            <option value="ticker">Ticker</option>
          </select>
        </label>

        <label class="text-sm">
          Duration (seconds)
          <input
            v-model.number="form.duration_seconds"
            type="number"
            min="0"
            class="mt-1 w-full rounded border border-slate-700 bg-slate-950 px-2 py-1.5"
          />
        </label>

        <label v-if="form.type !== 'ticker'" class="col-span-2 text-sm">
          Media URL
          <input
            v-model="form.url"
            type="url"
            placeholder="https://…"
            class="mt-1 w-full rounded border border-slate-700 bg-slate-950 px-2 py-1.5"
          />
        </label>

        <label v-else class="col-span-2 text-sm">
          Ticker message
          <textarea
            v-model="form.body"
            rows="2"
            class="mt-1 w-full rounded border border-slate-700 bg-slate-950 px-2 py-1.5"
          ></textarea>
        </label>

        <label class="text-sm">
          Sort order
          <input
            v-model.number="form.sort_order"
            type="number"
            min="0"
            class="mt-1 w-full rounded border border-slate-700 bg-slate-950 px-2 py-1.5"
          />
        </label>

        <label class="flex items-center gap-2 pt-6 text-sm">
          <input v-model="form.is_active" type="checkbox" class="rounded border-slate-700" />
          Active
        </label>
      </div>

      <p v-if="error" class="text-sm text-red-400">{{ error }}</p>

      <div class="flex justify-end gap-2 pt-2">
        <button type="button" class="rounded px-3 py-1.5 text-sm text-slate-400 hover:text-slate-100" @click="close">
          Cancel
        </button>
        <button
          type="submit"
          :disabled="saving"
          class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium hover:bg-indigo-500 disabled:opacity-50"
        >
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </form>
  </dialog>
</template>
