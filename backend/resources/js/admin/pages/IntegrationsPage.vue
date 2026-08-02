<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { api } from '@admin/api'
import type { GoogleIntegrationStatus } from '@admin/types'
import StatusBadge from '@admin/components/StatusBadge.vue'

const status = ref<GoogleIntegrationStatus | null>(null)
const loading = ref(true)
const syncing = ref(false)
const error = ref<string | null>(null)
const documentId = ref('')

// Surfaced from the OAuth callback's session-flashed error (see
// admin.blade.php) — read once at mount, since it's only ever relevant
// on the page load immediately following a redirect back from Google.
const oauthError = document
  .querySelector('meta[name="google-oauth-error"]')
  ?.getAttribute('content')

async function load() {
  try {
    status.value = await api.google.status()
    documentId.value = status.value.document_id ?? ''
    error.value = null
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load integration status'
  } finally {
    loading.value = false
  }
}

onMounted(load)

async function saveDocumentId() {
  if (!documentId.value.trim()) return
  status.value = await api.google.setDocumentId(documentId.value.trim())
}

async function sync() {
  syncing.value = true
  error.value = null
  try {
    status.value = await api.google.sync()
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Sync failed'
    // The failure itself (last_error, unchanged last_synced_at) is
    // recorded server-side regardless of how the request response was
    // handled — re-fetching status is what shows that in the UI.
    await load()
  } finally {
    syncing.value = false
  }
}

async function disconnect() {
  if (!confirm('Disconnect Google Workspace? The ticker will keep showing the last synced content.')) return
  status.value = await api.google.disconnect()
}
</script>

<template>
  <div class="max-w-xl space-y-6">
    <div>
      <h1 class="text-xl font-semibold">Integrations</h1>
      <p class="mt-1 text-sm text-slate-400">
        Pull a company announcement straight from a Google Doc into the ticker — no manual copy/paste,
        and it re-syncs on its own every 15 minutes.
      </p>
    </div>

    <p v-if="oauthError" class="rounded border border-red-900 bg-red-950/50 p-3 text-sm text-red-400">
      {{ oauthError }}
    </p>
    <p v-if="error" class="rounded border border-red-900 bg-red-950/50 p-3 text-sm text-red-400">
      {{ error }}
    </p>

    <p v-if="loading" class="text-sm text-slate-400">Loading…</p>

    <div v-else-if="status" class="space-y-4 rounded-lg border border-slate-800 p-5">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-2">
          <span class="font-medium">Google Workspace</span>
          <StatusBadge
            :variant="status.connected ? 'success' : 'neutral'"
            :text="status.connected ? 'Connected' : 'Not connected'"
          />
        </div>

        <a
          v-if="!status.connected"
          href="/admin/integrations/google/connect"
          class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium hover:bg-indigo-500"
        >
          Connect Google Workspace
        </a>
        <button v-else class="text-sm text-red-400 hover:text-red-300" @click="disconnect">
          Disconnect
        </button>
      </div>

      <template v-if="status.connected">
        <label class="block text-sm">
          Google Doc ID
          <span class="block text-xs font-normal text-slate-500">
            From the doc's URL: docs.google.com/document/d/<strong>this-part</strong>/edit
          </span>
          <div class="mt-1 flex gap-2">
            <input
              v-model="documentId"
              type="text"
              placeholder="1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms"
              class="w-full rounded border border-slate-700 bg-slate-950 px-2 py-1.5 text-sm"
            />
            <button
              class="shrink-0 rounded border border-slate-700 px-3 py-1.5 text-sm hover:bg-slate-800"
              @click="saveDocumentId"
            >
              Save
            </button>
          </div>
        </label>

        <div class="flex items-center justify-between border-t border-slate-800 pt-4 text-sm">
          <div class="text-slate-400">
            <template v-if="status.last_synced_at">
              Last synced {{ new Date(status.last_synced_at).toLocaleString() }}
            </template>
            <template v-else> Never synced yet </template>
            <p v-if="status.last_error" class="mt-1 text-red-400">{{ status.last_error }}</p>
          </div>

          <button
            :disabled="syncing || !status.document_id"
            class="rounded bg-indigo-600 px-3 py-1.5 text-sm font-medium hover:bg-indigo-500 disabled:opacity-50"
            @click="sync"
          >
            {{ syncing ? 'Syncing…' : 'Sync now' }}
          </button>
        </div>
      </template>
    </div>
  </div>
</template>
