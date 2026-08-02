<?php

namespace App\Events;

use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired whenever a schedule change should reach every screen immediately
 * (a manager pushes an urgent slide, a video is pulled for a rights
 * issue) rather than waiting for the next 30s poll.
 *
 * Implements ShouldBroadcast (not ShouldBroadcastNow) deliberately: the
 * dispatch is queued onto Redis so a slow WebSocket fan-out to a large
 * fleet never blocks the HTTP request that triggered the change in the
 * CMS/admin.
 */
class ScheduleUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        // A public channel: the player is an unauthenticated kiosk, not a
        // logged-in user, so there's no Laravel session to authorize a
        // PrivateChannel against. A production fleet would instead mint
        // a short-lived per-device token (Sanctum) and broadcast on
        // `PrivateChannel("signage.device.{$deviceUid}")` so a device
        // only receives updates meant for its own screen group.
        return [new Channel('signage.schedule')];
    }

    public function broadcastAs(): string
    {
        return 'schedule.updated';
    }

    /**
     * Send only what changed, not the full row set — the same
     * "smaller payload, less client-side parsing" principle as the
     * ETag'd polling endpoint applies even more here, since a push can
     * hit hundreds of screens at once.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'items' => MediaItemResource::collection(MediaItem::onAir()->get())->resolve(),
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
