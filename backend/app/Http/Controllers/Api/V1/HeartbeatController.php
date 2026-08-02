<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHeartbeatRequest;
use App\Models\PlayerDevice;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    /**
     * POST /api/v1/heartbeat
     *
     * Deliberately the cheapest possible write path: one upsert on the
     * device row (for last-seen/fleet-list purposes) and one insert on
     * an append-only heartbeats table (for the memory/uptime time series).
     * No broadcasting, no cache invalidation, no side effects — a device
     * that's already struggling on constrained hardware shouldn't have
     * its heartbeat call trigger expensive server-side work either.
     */
    public function store(StoreHeartbeatRequest $request): JsonResponse
    {
        $device = PlayerDevice::updateOrCreate(
            ['device_uid' => $request->validated('device_uid')],
            [
                'name' => $request->validated('name') ?? $request->validated('device_uid'),
                'last_seen_at' => now(),
            ],
        );

        $device->heartbeats()->create([
            'memory_used_mb' => $request->validated('memory_used_mb'),
            'memory_limit_mb' => $request->validated('memory_limit_mb'),
            'uptime_seconds' => $request->validated('uptime_seconds'),
            'app_version' => $request->validated('app_version'),
            'status' => $request->validated('status') ?? 'healthy',
        ]);

        // Echo the server clock back so the player can detect and correct
        // local clock drift — relevant for dayparted schedules on devices
        // that have no RTC battery and reset to epoch on every power cut.
        return response()->json([
            'ok' => true,
            'server_time' => now()->toIso8601String(),
        ], 201);
    }
}
