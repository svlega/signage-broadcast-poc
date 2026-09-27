import { describe, it, expect, beforeEach } from 'vitest'
import { reactive } from 'vue'
import { useContentManifest } from '@/composables/useContentManifest'
import type { MediaItem } from '@/types/media'

function makeItem(overrides: Partial<MediaItem> = {}): MediaItem {
  return {
    id: crypto.randomUUID(),
    type: 'slide',
    title: 'Untitled',
    url: null,
    body: null,
    duration: 10,
    order: 0,
    checksum: null,
    ...overrides,
  }
}

// fake-indexeddb's global store persists across tests in the same
// process (it's not reset between files/cases automatically the way a
// real browser profile would be per test run) — deleting it before each
// test is what keeps these independent instead of order-dependent.
function deleteManifestDatabase(): Promise<void> {
  return new Promise((resolve, reject) => {
    const req = indexedDB.deleteDatabase('signage-player')
    req.onsuccess = () => resolve()
    req.onerror = () => reject(req.error)
  })
}

beforeEach(async () => {
  await deleteManifestDatabase()
})

describe('useContentManifest', () => {
  it('returns an empty array when nothing has been stored yet', async () => {
    const { loadAll } = useContentManifest()

    expect(await loadAll()).toEqual([])
  })

  it('round-trips items through replaceAll/loadAll', async () => {
    const { replaceAll, loadAll } = useContentManifest()
    await replaceAll([makeItem({ id: 'a', title: 'A' }), makeItem({ id: 'b', title: 'B' })])

    const loaded = await loadAll()

    expect(loaded.map((item) => item.title)).toEqual(['A', 'B'])
  })

  it('returns items sorted by order, regardless of insertion order', async () => {
    const { replaceAll, loadAll } = useContentManifest()
    await replaceAll([
      makeItem({ id: 'c', title: 'Third', order: 2 }),
      makeItem({ id: 'a', title: 'First', order: 0 }),
      makeItem({ id: 'b', title: 'Second', order: 1 }),
    ])

    const loaded = await loadAll()

    expect(loaded.map((item) => item.title)).toEqual(['First', 'Second', 'Third'])
  })

  it('replaceAll fully replaces the previous set rather than merging with it', async () => {
    const { replaceAll, loadAll } = useContentManifest()
    await replaceAll([makeItem({ id: 'old', title: 'Old' })])
    await replaceAll([makeItem({ id: 'new', title: 'New' })])

    const loaded = await loadAll()

    expect(loaded.map((item) => item.title)).toEqual(['New'])
  })

  it('marks loaded items raw so Vue never wraps them in a reactive proxy', async () => {
    const { replaceAll, loadAll } = useContentManifest()
    await replaceAll([makeItem({ id: 'a' })])

    const [loaded] = await loadAll()

    // reactive() is a no-op on a markRaw'd object — it hands back the
    // exact same reference instead of wrapping it in a Proxy.
    expect(reactive(loaded!)).toBe(loaded)
  })

  describe('applyDelta', () => {
    it('upserts changed items without touching items the delta never mentions', async () => {
      const { replaceAll, applyDelta } = useContentManifest()
      await replaceAll([makeItem({ id: 'a', title: 'A', order: 0 }), makeItem({ id: 'b', title: 'B', order: 1 })])

      const merged = await applyDelta([makeItem({ id: 'a', title: 'A (updated)', order: 0 })], [])

      expect(merged.map((item) => item.title)).toEqual(['A (updated)', 'B'])
    })

    it('removes exactly the deleted ids and nothing else', async () => {
      const { replaceAll, applyDelta } = useContentManifest()
      await replaceAll([
        makeItem({ id: 'a', title: 'A', order: 0 }),
        makeItem({ id: 'b', title: 'B', order: 1 }),
        makeItem({ id: 'c', title: 'C', order: 2 }),
      ])

      const merged = await applyDelta([], ['b'])

      expect(merged.map((item) => item.id)).toEqual(['a', 'c'])
    })

    it('inserts an item the manifest has never seen before, matching first-sync semantics', async () => {
      const { applyDelta } = useContentManifest()

      const merged = await applyDelta([makeItem({ id: 'new', title: 'Brand new' })], [])

      expect(merged.map((item) => item.title)).toEqual(['Brand new'])
    })

    it('deleting an id the manifest never held is a harmless no-op', async () => {
      const { replaceAll, applyDelta } = useContentManifest()
      await replaceAll([makeItem({ id: 'a', title: 'A' })])

      const merged = await applyDelta([], ['never-existed'])

      expect(merged.map((item) => item.id)).toEqual(['a'])
    })

    it('applies both an upsert and a deletion from the same delta', async () => {
      const { replaceAll, applyDelta } = useContentManifest()
      await replaceAll([
        makeItem({ id: 'keep', title: 'Keep', order: 0 }),
        makeItem({ id: 'gone', title: 'Gone', order: 1 }),
      ])

      const merged = await applyDelta([makeItem({ id: 'keep', title: 'Keep (edited)', order: 0 })], ['gone'])

      expect(merged.map((item) => [item.id, item.title])).toEqual([['keep', 'Keep (edited)']])
    })
  })

  describe('sync watermark', () => {
    it('returns null when no sync has happened yet', async () => {
      const { getLastSyncedAt } = useContentManifest()

      expect(await getLastSyncedAt()).toBeNull()
    })

    it('round-trips the server-issued watermark', async () => {
      const { setLastSyncedAt, getLastSyncedAt } = useContentManifest()

      await setLastSyncedAt('2026-08-19T12:40:54+00:00')

      expect(await getLastSyncedAt()).toBe('2026-08-19T12:40:54+00:00')
    })

    it('a later sync overwrites the previous watermark rather than accumulating', async () => {
      const { setLastSyncedAt, getLastSyncedAt } = useContentManifest()

      await setLastSyncedAt('2026-08-19T12:00:00+00:00')
      await setLastSyncedAt('2026-08-19T12:15:00+00:00')

      expect(await getLastSyncedAt()).toBe('2026-08-19T12:15:00+00:00')
    })
  })
})
