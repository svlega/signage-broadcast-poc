import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { effectScope, type EffectScope } from 'vue'
import { useDisplaySchedule } from '@/composables/useDisplaySchedule'
import { useContentManifest } from '@/composables/useContentManifest'
import type { MediaItem, SyncResponse } from '@/types/media'

function makeItem(overrides: Partial<MediaItem> = {}): MediaItem {
  return {
    id: 'a',
    type: 'slide',
    title: 'A',
    url: null,
    body: null,
    duration: 10,
    order: 0,
    checksum: null,
    ...overrides,
  }
}

function jsonResponse(body: SyncResponse): Response {
  return { ok: true, status: 200, json: async () => body } as Response
}

function deleteManifestDatabase(): Promise<void> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.deleteDatabase('signage-player')
    req.onsuccess = () => resolve()
    req.onerror = () => reject(req.error)
  })
}

let scope: EffectScope

beforeEach(async () => {
  await deleteManifestDatabase()
})

afterEach(() => {
  scope?.stop()
  vi.unstubAllGlobals()
})

describe('useDisplaySchedule', () => {
  it('does not reassign schedule.value on a no-op sync', async () => {
    // Regression test: schedule is a shallowRef, and a downstream watcher
    // (useSlideRotation) triggers on *reference* reassignment, not on
    // whether the contents actually differ. Handing back even an
    // identical array on every poll — which is what applyDelta's
    // unconditional loadAll() used to do — would silently reset the
    // current slide's dwell timer on every tick, even when nothing
    // changed on the server. This covers the *200-with-an-empty-delta*
    // case specifically — a write happened somewhere, just not to
    // anything this device's on-air set cares about — which is exactly
    // the case the 304 fast path (tested separately below) can't itself
    // catch, since from the server's cheap check alone it can't yet tell
    // the two apart.
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        jsonResponse({ updated: [makeItem()], deleted: [], server_time: '2026-01-01T00:00:00Z' }),
      )
      .mockResolvedValueOnce(jsonResponse({ updated: [], deleted: [], server_time: '2026-01-01T00:00:30Z' }))
    vi.stubGlobal('fetch', fetchMock)

    scope = effectScope()
    const { schedule, start, refetch } = scope.run(() => useDisplaySchedule())!

    await start()
    const referenceAfterFirstSync = schedule.value

    await refetch()

    expect(fetchMock).toHaveBeenCalledTimes(2)
    expect(schedule.value).toBe(referenceAfterFirstSync)
  })

  it('reassigns schedule.value when a sync reports real changes', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        jsonResponse({ updated: [makeItem({ title: 'A' })], deleted: [], server_time: '2026-01-01T00:00:00Z' }),
      )
      .mockResolvedValueOnce(
        jsonResponse({
          updated: [makeItem({ title: 'A (edited)' })],
          deleted: [],
          server_time: '2026-01-01T00:00:30Z',
        }),
      )
    vi.stubGlobal('fetch', fetchMock)

    scope = effectScope()
    const { schedule, start, refetch } = scope.run(() => useDisplaySchedule())!

    await start()
    const referenceAfterFirstSync = schedule.value

    await refetch()

    expect(schedule.value).not.toBe(referenceAfterFirstSync)
    expect(schedule.value[0]?.title).toBe('A (edited)')
  })

  it('sends the persisted watermark as ?since= on the next sync', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        jsonResponse({ updated: [makeItem()], deleted: [], server_time: '2026-01-01T00:00:00Z' }),
      )
      .mockResolvedValueOnce(jsonResponse({ updated: [], deleted: [], server_time: '2026-01-01T00:00:30Z' }))
    vi.stubGlobal('fetch', fetchMock)

    scope = effectScope()
    const { start, refetch } = scope.run(() => useDisplaySchedule())!

    await start()
    await refetch()

    const secondCallUrl = fetchMock.mock.calls[1]?.[0] as URL
    expect(secondCallUrl.searchParams.get('since')).toBe('2026-01-01T00:00:00Z')
  })

  it('omits ?since= entirely on the very first sync', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ updated: [], deleted: [], server_time: '2026-01-01T00:00:00Z' }))
    vi.stubGlobal('fetch', fetchMock)

    scope = effectScope()
    const { start } = scope.run(() => useDisplaySchedule())!

    await start()

    const firstCallUrl = fetchMock.mock.calls[0]?.[0] as URL
    expect(firstCallUrl.searchParams.has('since')).toBe(false)
  })

  it('does not touch schedule.value or crash trying to parse a body on a 304', async () => {
    const noContentResponse = {
      ok: false, // real fetch() Response.ok is false for 304 — outside the 200–299 range
      status: 304,
      json: async () => {
        throw new Error('a 304 has no body — syncSchedule must never call response.json() on one')
      },
    } as unknown as Response

    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        jsonResponse({ updated: [makeItem()], deleted: [], server_time: '2026-01-01T00:00:00Z' }),
      )
      .mockResolvedValueOnce(noContentResponse)
    vi.stubGlobal('fetch', fetchMock)

    scope = effectScope()
    const { schedule, isOffline, start, refetch } = scope.run(() => useDisplaySchedule())!

    await start()
    const referenceAfterFirstSync = schedule.value

    await expect(refetch()).resolves.toBeUndefined()

    expect(schedule.value).toBe(referenceAfterFirstSync)
    expect(isOffline.value).toBe(false)
  })

  it('keeps the existing watermark across a 304, rather than losing or blanking it', async () => {
    const noContentResponse = { ok: false, status: 304, json: async () => ({}) } as unknown as Response

    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce(
        jsonResponse({ updated: [makeItem()], deleted: [], server_time: '2026-01-01T00:00:00Z' }),
      )
      .mockResolvedValueOnce(noContentResponse)
    vi.stubGlobal('fetch', fetchMock)

    scope = effectScope()
    const { start, refetch } = scope.run(() => useDisplaySchedule())!

    await start()
    await refetch() // the 304

    // A 304 means "nothing changed since `since`" — so that same `since`
    // is still exactly correct for the *next* sync too. There's no new
    // server_time in a 304 response to advance it to, and there's no
    // need to: advancing it to the client's own clock here is exactly
    // the drift risk the server-issued watermark exists to avoid.
    const thirdFetchMock = vi
      .fn()
      .mockResolvedValueOnce(jsonResponse({ updated: [], deleted: [], server_time: '2026-01-01T00:01:00Z' }))
    vi.stubGlobal('fetch', thirdFetchMock)

    await refetch()

    const thirdCallUrl = thirdFetchMock.mock.calls[0]?.[0] as URL
    expect(thirdCallUrl.searchParams.get('since')).toBe('2026-01-01T00:00:00Z')
  })

  it('loads the persisted manifest on a 304 when schedule is still empty', async () => {
    // Regression test: a page reload starts `schedule` empty in memory
    // (it's a shallowRef, not itself persisted) even though the manifest
    // and its watermark already live on disk from a previous session. If
    // that reload's very first sync lands on a 304 — nothing changed on
    // the server since this device's last visit — the old code returned
    // immediately without ever reading the manifest back out, leaving the
    // player on "No content scheduled" forever despite the full playlist
    // sitting right there in IndexedDB.
    const { replaceAll, setLastSyncedAt } = useContentManifest()
    await replaceAll([makeItem({ id: 'persisted', title: 'Persisted Slide' })])
    await setLastSyncedAt('2026-01-01T00:00:00Z')

    const noContentResponse = { ok: false, status: 304, json: async () => ({}) } as unknown as Response
    const fetchMock = vi.fn().mockResolvedValueOnce(noContentResponse)
    vi.stubGlobal('fetch', fetchMock)

    scope = effectScope()
    const { schedule, start } = scope.run(() => useDisplaySchedule())!

    await start()

    expect(schedule.value).toHaveLength(1)
    expect(schedule.value[0]?.title).toBe('Persisted Slide')
  })
})
