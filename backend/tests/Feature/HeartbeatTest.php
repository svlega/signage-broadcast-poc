<?php

namespace Tests\Feature;

use App\Models\PlayerDevice;
use App\Models\PlayerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'device_uid' => 'lobby-screen-01',
            'memory_used_mb' => 128,
            'memory_limit_mb' => 512,
            'uptime_seconds' => 3600,
            'app_version' => '1.0.0',
            'status' => 'healthy',
        ], $overrides);
    }

    public function test_it_records_a_heartbeat_and_creates_the_device(): void
    {
        $response = $this->postJson('/api/v1/heartbeat', $this->validPayload());

        $response->assertCreated();
        $response->assertJson(['ok' => true]);
        $response->assertJsonStructure(['ok', 'server_time']);

        $this->assertDatabaseHas('player_devices', ['device_uid' => 'lobby-screen-01']);
        $this->assertDatabaseHas('player_heartbeats', [
            'memory_used_mb' => 128,
            'uptime_seconds' => 3600,
            'status' => 'healthy',
        ]);
    }

    public function test_repeated_heartbeats_from_the_same_device_reuse_one_device_row(): void
    {
        $this->postJson('/api/v1/heartbeat', $this->validPayload(['uptime_seconds' => 30]));
        $this->postJson('/api/v1/heartbeat', $this->validPayload(['uptime_seconds' => 60]));

        // One physical screen, two health samples — this is exactly the
        // append-only time series the fleet dashboard's memory-creep
        // detection depends on.
        $this->assertSame(1, PlayerDevice::query()->count());
        $this->assertSame(2, PlayerHeartbeat::query()->count());
    }

    public function test_it_updates_last_seen_at_on_the_device(): void
    {
        $this->postJson('/api/v1/heartbeat', $this->validPayload());

        $device = PlayerDevice::query()->firstOrFail();

        $this->assertNotNull($device->last_seen_at);
        $this->assertTrue($device->last_seen_at->isToday());
    }

    public function test_it_requires_a_device_uid(): void
    {
        $response = $this->postJson('/api/v1/heartbeat', $this->validPayload(['device_uid' => null]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('device_uid');
    }

    public function test_it_requires_numeric_memory_and_uptime(): void
    {
        $response = $this->postJson('/api/v1/heartbeat', $this->validPayload([
            'memory_used_mb' => 'not-a-number',
            'uptime_seconds' => -1,
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['memory_used_mb', 'uptime_seconds']);
    }

    public function test_it_rejects_an_unknown_status_value(): void
    {
        $response = $this->postJson('/api/v1/heartbeat', $this->validPayload(['status' => 'on-fire']));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('status');
    }

    public function test_status_defaults_to_healthy_when_omitted(): void
    {
        $payload = $this->validPayload();
        unset($payload['status']);

        $response = $this->postJson('/api/v1/heartbeat', $payload);

        $response->assertCreated();
        $this->assertDatabaseHas('player_heartbeats', ['status' => 'healthy']);
    }
}
