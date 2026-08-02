export type MediaType = 'video' | 'slide' | 'ticker'

export interface MediaItem {
  id: number
  uuid: string
  type: MediaType
  title: string
  url: string | null
  body: string | null
  duration_seconds: number
  sort_order: number
  starts_at: string | null
  ends_at: string | null
  is_active: boolean
  checksum: string | null
  updated_at: string | null
}

export type MediaItemPayload = Omit<MediaItem, 'id' | 'uuid' | 'checksum' | 'updated_at'>

export interface DeviceHeartbeat {
  memory_used_mb: number
  memory_limit_mb: number | null
  uptime_seconds: number
  status: 'healthy' | 'degraded' | 'critical'
  reported_at: string
}

export interface Device {
  id: number
  device_uid: string
  name: string | null
  location: string | null
  last_seen_at: string | null
  is_online: boolean
  latest_heartbeat: DeviceHeartbeat | null
}

export interface GoogleIntegrationStatus {
  connected: boolean
  document_id: string | null
  media_item_id: number | null
  last_synced_at: string | null
  last_error: string | null
}
