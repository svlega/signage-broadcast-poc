import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { effectScope, nextTick, shallowRef, type EffectScope } from 'vue'
import { useSlideRotation } from '@/composables/useSlideRotation'
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

/**
 * useSlideRotation calls onUnmounted, which needs an active effect scope
 * to attach to outside of a real component. `effectScope()` + `scope.run()`
 * gives it exactly that without paying for a DOM mount via @vue/test-utils
 * — this composable never touches the DOM, so a real component instance
 * would be pure overhead here.
 */
let scope: EffectScope

function setup(items: MediaItem[]) {
  const schedule = shallowRef<MediaItem[]>(items)
  scope = effectScope()
  const rotation = scope.run(() => useSlideRotation(schedule))!
  return { schedule, ...rotation }
}

beforeEach(() => {
  vi.useFakeTimers()
})

afterEach(() => {
  scope?.stop()
  vi.useRealTimers()
})

describe('useSlideRotation', () => {
  it('starts on the first item in the schedule', () => {
    const { currentItem } = setup([makeItem({ title: 'A' }), makeItem({ title: 'B' })])

    expect(currentItem.value?.title).toBe('A')
  })

  it('renders nothing when the schedule is empty', () => {
    const { currentItem } = setup([])

    expect(currentItem.value).toBeNull()
  })

  it('advance() moves to the next item and wraps back to the start', () => {
    const { currentItem, advance } = setup([makeItem({ title: 'A' }), makeItem({ title: 'B' })])

    advance()
    expect(currentItem.value?.title).toBe('B')

    advance()
    expect(currentItem.value?.title).toBe('A')
  })

  it('auto-advances a slide once its duration elapses', () => {
    const { currentItem } = setup([makeItem({ title: 'A', duration: 5 }), makeItem({ title: 'B' })])

    vi.advanceTimersByTime(4_999)
    expect(currentItem.value?.title).toBe('A')

    vi.advanceTimersByTime(1)
    expect(currentItem.value?.title).toBe('B')
  })

  it('never auto-advances a video — only an external advance() moves past it', () => {
    const { currentItem } = setup([
      makeItem({ title: 'A', type: 'video', duration: 5 }),
      makeItem({ title: 'B' }),
    ])

    // A duplicate timer racing the <video>'s own `ended` event is exactly
    // the bug this behavior prevents — see useSlideRotation's comment.
    vi.advanceTimersByTime(60_000)
    expect(currentItem.value?.title).toBe('A')
  })

  it('re-arms its timer for the new item after advancing', async () => {
    const { currentItem } = setup([
      makeItem({ title: 'A', duration: 5 }),
      makeItem({ title: 'B', duration: 5 }),
    ])

    vi.advanceTimersByTime(5_000)
    expect(currentItem.value?.title).toBe('B')

    // The re-arm itself happens in a `watch` callback, which (unlike the
    // computed above) is deferred to Vue's scheduler — a real browser's
    // event loop always drains that microtask before the next timer
    // fires, but fake timers don't do that automatically, so the test
    // has to wait for it explicitly.
    await nextTick()

    vi.advanceTimersByTime(5_000)
    expect(currentItem.value?.title).toBe('A')
  })

  it('keeps showing the on-screen item, unmutated, even if the schedule shrinks out from under it', async () => {
    // Phase 4: an update that reaches the currently-playing item — even
    // one that deletes it — must never change what's on screen mid
    // display. It's staged and only picked up at the next advance().
    const { currentItem, advance, schedule } = setup([
      makeItem({ title: 'A' }),
      makeItem({ title: 'B' }),
      makeItem({ title: 'C' }),
    ])

    advance()
    advance()
    expect(currentItem.value?.title).toBe('C')

    // A live push (useSignageSocket) can swap in a shorter playlist —
    // including one where 'C' no longer exists at all — at any point in
    // the rotation.
    schedule.value = [makeItem({ title: 'X' })]
    await nextTick()

    expect(currentItem.value?.title).toBe('C')

    advance()

    expect(currentItem.value?.title).toBe('X')
  })

  it('applies an update to a different (off-screen) item immediately, not just at the next advance', async () => {
    const a = makeItem({ title: 'A' })
    const { schedule } = setup([a, makeItem({ title: 'B' })])

    schedule.value = [a, makeItem({ id: schedule.value[1]!.id, title: 'B (edited)' })]
    await nextTick()

    // Nothing pins the *rest* of the array — only currentItem is frozen —
    // so a change to an item that isn't on screen is visible right away.
    expect(schedule.value[1]?.title).toBe('B (edited)')
  })

  it('does not mutate the on-screen item when its own data changes, only once advance() picks it back up', async () => {
    const original = makeItem({ title: 'A', url: 'https://example.com/original.jpg' })
    const { currentItem, advance, schedule } = setup([original, makeItem({ title: 'B' })])

    expect(currentItem.value?.url).toBe('https://example.com/original.jpg')

    // Same id, same slot, edited content — e.g. an admin re-saved the
    // currently-playing item, minting a fresh checksum.
    schedule.value = [
      { ...original, url: 'https://example.com/edited.jpg', checksum: 'new-checksum' },
      schedule.value[1]!,
    ]
    await nextTick()

    expect(currentItem.value?.url).toBe('https://example.com/original.jpg')

    advance() // to 'B'
    advance() // wraps back to 'A' — now picks up the edit

    expect(currentItem.value?.url).toBe('https://example.com/edited.jpg')
  })

  it('clears its pending timer on cleanup', () => {
    setup([makeItem({ duration: 10 })])

    expect(vi.getTimerCount()).toBeGreaterThan(0)

    scope.stop()

    expect(vi.getTimerCount()).toBe(0)
  })
})
