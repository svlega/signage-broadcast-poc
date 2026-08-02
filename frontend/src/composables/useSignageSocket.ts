import { shallowRef, markRaw, onUnmounted } from 'vue'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import type { MediaItem } from '@/types/media'

export type SocketStatus = 'connecting' | 'connected' | 'disconnected'

interface ScheduleUpdatedPayload {
  items: MediaItem[]
  updated_at: string
}

/**
 * Real-time push over Laravel Reverb (a WebSocket server speaking the
 * Pusher protocol). This is what makes an urgent content change reach
 * every screen in ~seconds instead of waiting for the next HTTP poll in
 * useDisplaySchedule — that composable stays running underneath this one
 * purely as a fallback if the socket can't be reached.
 *
 * Everything this composable creates (the Echo client, its WebSocket, its
 * event listener) is torn down in onUnmounted. On a page that's designed
 * to run for weeks, "the component happened to only mount once" is not a
 * safe assumption to skip cleanup on — a hot-reload in dev, or a
 * navigation in a less minimal app, would otherwise leak a live socket
 * and an event handler on every remount.
 */
export function useSignageSocket(onScheduleUpdated: (items: MediaItem[]) => void) {
  const status = shallowRef<SocketStatus>('connecting')

  // window.Pusher is how laravel-echo's pusher transport locates its
  // WebSocket implementation; wiring it explicitly (vs. a global script
  // tag) keeps this composable self-contained and tree-shakeable.
  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher

  const echo = markRaw(
    new Echo({
      broadcaster: 'reverb',
      key: import.meta.env.VITE_REVERB_APP_KEY,
      wsHost: import.meta.env.VITE_REVERB_HOST,
      wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
      forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
      enabledTransports: ['ws', 'wss'],
      // A kiosk player has no logged-in user, so it never needs the
      // CSRF-cookie/session dance Echo's default authorizer expects —
      // pointing it at the API's (unauthenticated) v1 base keeps public
      // channels working without a Sanctum session in the loop.
      authEndpoint: `${import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000'}/broadcasting/auth`,
    }),
  )

  const channel = echo.channel('signage.schedule')

  channel.subscribed(() => {
    status.value = 'connected'
  })

  // The leading "." tells Echo this is a fully-qualified broadcast name
  // (App\Events\ScheduleUpdated::broadcastAs) rather than a class name
  // Echo should namespace-resolve itself.
  channel.listen('.schedule.updated', (payload: ScheduleUpdatedPayload) => {
    onScheduleUpdated(payload.items)
  })

  echo.connector.pusher.connection.bind('connecting', () => {
    status.value = 'connecting'
  })
  echo.connector.pusher.connection.bind('unavailable', () => {
    status.value = 'disconnected'
  })
  echo.connector.pusher.connection.bind('failed', () => {
    status.value = 'disconnected'
  })

  onUnmounted(() => {
    echo.leave('signage.schedule')
    echo.disconnect()
  })

  return { status }
}
