<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $heartbeat = $this->latestHeartbeat->first();

        return [
            'id' => $this->id,
            'device_uid' => $this->device_uid,
            'name' => $this->name,
            'location' => $this->location,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            // A device is "online" if it's checked in within 3 missed
            // heartbeat intervals (30s cadence, see the frontend's
            // useHeartbeat.ts) — one or two misses is ordinary network
            // jitter; three in a row is a screen that's actually down.
            'is_online' => $this->last_seen_at?->isAfter(now()->subSeconds(90)) ?? false,

            'latest_heartbeat' => $heartbeat ? [
                'memory_used_mb' => $heartbeat->memory_used_mb,
                'memory_limit_mb' => $heartbeat->memory_limit_mb,
                'uptime_seconds' => $heartbeat->uptime_seconds,
                'status' => $heartbeat->status,
                'reported_at' => $heartbeat->created_at->toIso8601String(),
            ] : null,
        ];
    }
}
