export type MediaType = 'video' | 'slide' | 'ticker'

export interface MediaItem {
  id: string // UUID — stable IndexedDB / Cache Storage key, see useOfflineCache
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

export interface HeartbeatPayload {
  device_uid: string
  memory_used_mb: number
  memory_limit_mb: number | null
  uptime_seconds: number
  app_version: string
  status: 'healthy' | 'degraded' | 'critical'
}
