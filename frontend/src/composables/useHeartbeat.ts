import { onUnmounted } from 'vue'
import type { HeartbeatPayload } from '@/types/media'

const API_BASE = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000'
const APP_VERSION = import.meta.env.VITE_APP_VERSION ?? '1.0.0'
const HEARTBEAT_INTERVAL_MS = 30_000

// Chrome/Chromium's non-standard performance.memory API. This is exactly
// the runtime this player targets (Android WebView, ChromeOS kiosk mode),
// so the tradeoff of a Chromium-only API is acceptable here — feature
// detection below keeps it from throwing on any other engine.
interface ChromePerformanceMemory {
  usedJSHeapSize: number
  jsHeapSizeLimit: number
}

/**
 * Reports player health on an interval. This exists because a signage
 * box is, almost by definition, somewhere nobody is looking at it — the
 * heartbeat is the only signal ops has that a screen is alive, and the
 * memory reading is the leading indicator of the classic 24/7-tab
 * failure mode (a slow leak that ends in a Chromium OOM-kill days or
 * weeks after deployment, long after anyone would think to check on it).
 */
export function useHeartbeat() {
  const deviceUid = getOrCreateDeviceUid()
  const bootTime = performance.now()

  function readMemoryMb(): { used: number; limit: number | null } {
    const memory = (performance as unknown as { memory?: ChromePerformanceMemory }).memory

    if (!memory) return { used: 0, limit: null }

    return {
      used: Math.round(memory.usedJSHeapSize / 1024 / 1024),
      limit: Math.round(memory.jsHeapSizeLimit / 1024 / 1024),
    }
  }

  function deriveStatus(usedMb: number, limitMb: number | null): HeartbeatPayload['status'] {
    if (!limitMb) return 'healthy'
    const ratio = usedMb / limitMb
    if (ratio > 0.9) return 'critical'
    if (ratio > 0.7) return 'degraded'
    return 'healthy'
  }

  async function sendHeartbeat(): Promise<void> {
    const { used, limit } = readMemoryMb()

    const payload: HeartbeatPayload = {
      device_uid: deviceUid,
      memory_used_mb: used,
      memory_limit_mb: limit,
      uptime_seconds: Math.round((performance.now() - bootTime) / 1000),
      app_version: APP_VERSION,
      status: deriveStatus(used, limit),
    }

    try {
      // `keepalive` lets this request complete even if it's fired right
      // as the tab is being torn down (power cut, kiosk-mode reload) —
      // the one heartbeat that would otherwise be lost is often the most
      // diagnostically useful one.
      await fetch(`${API_BASE}/api/v1/heartbeat`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive: true,
      })
    } catch {
      // A failed heartbeat is expected during an offline stretch and is
      // not itself actionable client-side — the next successful
      // heartbeat's uptime/status fields tell the full story server-side.
    }
  }

  const timer = setInterval(sendHeartbeat, HEARTBEAT_INTERVAL_MS)
  void sendHeartbeat() // first sample immediately, don't wait 30s

  onUnmounted(() => clearInterval(timer))
}

function getOrCreateDeviceUid(): string {
  const STORAGE_KEY = 'signage.device_uid'

  // localStorage, not IndexedDB: this is a single small string read once
  // at boot, not a hot path — using the async API here would only add
  // ceremony with no measurable benefit.
  const existing = localStorage.getItem(STORAGE_KEY)
  if (existing) return existing

  const uid = crypto.randomUUID()
  localStorage.setItem(STORAGE_KEY, uid)
  return uid
}
