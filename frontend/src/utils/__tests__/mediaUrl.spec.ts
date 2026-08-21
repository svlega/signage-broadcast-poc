import { describe, it, expect } from 'vitest'
import { cacheBustedMediaUrl } from '@/utils/mediaUrl'

describe('cacheBustedMediaUrl', () => {
  it('returns null when there is no url at all', () => {
    expect(cacheBustedMediaUrl({ url: null, checksum: 'abc' })).toBeNull()
  })

  it('returns the plain url unchanged when there is no checksum', () => {
    expect(cacheBustedMediaUrl({ url: 'https://cdn.example.com/slide.jpg', checksum: null })).toBe(
      'https://cdn.example.com/slide.jpg',
    )
  })

  it('appends the checksum as a query param', () => {
    const result = cacheBustedMediaUrl({ url: 'https://cdn.example.com/slide.jpg', checksum: 'abc123' })

    expect(result).toBe('https://cdn.example.com/slide.jpg?v=abc123')
  })

  it('preserves existing query params on the source url', () => {
    const result = cacheBustedMediaUrl({
      url: 'https://cdn.example.com/slide.jpg?w=1920&h=1080',
      checksum: 'abc123',
    })

    const parsed = new URL(result!)
    expect(parsed.searchParams.get('w')).toBe('1920')
    expect(parsed.searchParams.get('h')).toBe('1080')
    expect(parsed.searchParams.get('v')).toBe('abc123')
  })

  it('produces the identical url for the identical checksum — a stable cache key', () => {
    const item = { url: 'https://cdn.example.com/slide.jpg', checksum: 'abc123' }

    expect(cacheBustedMediaUrl(item)).toBe(cacheBustedMediaUrl(item))
  })

  it('produces a different url when the checksum changes — the actual cache-busting behavior', () => {
    const before = cacheBustedMediaUrl({ url: 'https://cdn.example.com/slide.jpg', checksum: 'abc123' })
    const after = cacheBustedMediaUrl({ url: 'https://cdn.example.com/slide.jpg', checksum: 'def456' })

    expect(before).not.toBe(after)
  })
})
