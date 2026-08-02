import type { Device, GoogleIntegrationStatus, MediaItem, MediaItemPayload } from '@admin/types'

/**
 * Same-origin session auth: the CSRF token comes from the meta tag
 * admin.blade.php embeds server-side, not from a JS-readable cookie or
 * an Authorization header. This only works because the admin SPA is
 * served by this same Laravel app — the kiosk player, being a
 * cross-origin static build, deliberately cannot use this pattern and
 * has no equivalent auth at all (see its own README section).
 */
function csrfToken(): string {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? 'GET').toUpperCase()

  const response = await fetch(`/admin/api${path}`, {
    ...options,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      // Only state-changing methods need the token; a stray GET carrying
      // it is harmless but unnecessary.
      ...(method !== 'GET' ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
      ...options.headers,
    },
  })

  if (response.status === 401 || response.status === 419) {
    // Session expired mid-visit (419 = CSRF token stale, 401 = logged
    // out elsewhere) — a full navigation is the correct recovery, not a
    // toast, since every in-flight bit of client state is now invalid
    // anyway.
    window.location.href = '/admin/login'
    return new Promise(() => {}) // navigation is about to unload the page
  }

  if (!response.ok) {
    const body = await response.json().catch(() => null)
    throw new Error(body?.message ?? `Request failed (${response.status})`)
  }

  if (response.status === 204) return undefined as T

  return response.json() as Promise<T>
}

export const api = {
  mediaItems: {
    list: () => request<{ data: MediaItem[] }>('/media-items').then((r) => r.data),
    create: (payload: MediaItemPayload) =>
      request<{ data: MediaItem }>('/media-items', {
        method: 'POST',
        body: JSON.stringify(payload),
      }).then((r) => r.data),
    update: (id: number, payload: MediaItemPayload) =>
      request<{ data: MediaItem }>(`/media-items/${id}`, {
        method: 'PUT',
        body: JSON.stringify(payload),
      }).then((r) => r.data),
    delete: (id: number) => request<void>(`/media-items/${id}`, { method: 'DELETE' }),
  },
  devices: {
    list: () => request<{ data: Device[] }>('/devices').then((r) => r.data),
  },
  google: {
    status: () => request<GoogleIntegrationStatus>('/integrations/google'),
    setDocumentId: (documentId: string) =>
      request<GoogleIntegrationStatus>('/integrations/google', {
        method: 'PUT',
        body: JSON.stringify({ document_id: documentId }),
      }),
    sync: () => request<GoogleIntegrationStatus>('/integrations/google/sync', { method: 'POST' }),
    disconnect: () =>
      request<GoogleIntegrationStatus>('/integrations/google/disconnect', { method: 'POST' }),
  },
}
