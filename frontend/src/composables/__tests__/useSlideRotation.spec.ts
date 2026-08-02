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

  it('clamps the index back into range if the schedule shrinks mid-rotation', async () => {
    const { currentItem, advance, schedule } = setup([
      makeItem({ title: 'A' }),
      makeItem({ title: 'B' }),
      makeItem({ title: 'C' }),
    ])

    advance()
    advance()
    expect(currentItem.value?.title).toBe('C')

    // A live push (useSignageSocket) can swap in a shorter playlist at
    // any point in the rotation — the watcher that reacts to this runs
    // on Vue's scheduler, hence the tick.
    schedule.value = [makeItem({ title: 'X' })]
    await nextTick()

    expect(currentItem.value?.title).toBe('X')
  })

  it('clears its pending timer on cleanup', () => {
    setup([makeItem({ duration: 10 })])

    expect(vi.getTimerCount()).toBeGreaterThan(0)

    scope.stop()

    expect(vi.getTimerCount()).toBe(0)
  })
})
