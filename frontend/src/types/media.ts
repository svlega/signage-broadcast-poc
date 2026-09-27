export type MediaType = 'video' | 'slide' | 'ticker'

export interface MediaItem {
  id: string // UUID — stable IndexedDB / Cache Storage key, see useContentManifest
  type: MediaType
  title: string
  url: string | null
  body: string | null
  duration: number // seconds; ignored for `video` (plays to `ended`)
  order: number
  checksum: string | null
}

export interface ScheduleResponse {
  data: MediaItem[]
  meta: {
    generated_at: string
    poll_after_seconds: number
  }
}

/**
 * GET /api/v1/display-schedule/sync?since=... — the delta counterpart
 * to ScheduleResponse above. `updated` and `deleted` are deliberately
 * both always present (never omitted when empty): "nothing changed"
 * is `{ updated: [], deleted: [] }`, not a missing key the client has
 * to treat as a special case.
 */
export interface SyncResponse {
  updated: MediaItem[]
  deleted: string[]
  server_time: string
}

export interface HeartbeatPayload {
  device_uid: string
  memory_used_mb: number
  memory_limit_mb: number | null
  uptime_seconds: number
  app_version: string
  status: 'healthy' | 'degraded' | 'critical'
}
