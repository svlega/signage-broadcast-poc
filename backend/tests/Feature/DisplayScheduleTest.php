<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_active_items_in_sort_order(): void
    {
        MediaItem::factory()->create(['title' => 'Second', 'sort_order' => 2, 'is_active' => true]);
        MediaItem::factory()->create(['title' => 'First', 'sort_order' => 1, 'is_active' => true]);

        $response = $this->getJson('/api/v1/display-schedule');

        $response->assertOk();
        $response->assertJsonPath('data.0.title', 'First');
        $response->assertJsonPath('data.1.title', 'Second');
    }

    public function test_it_excludes_inactive_items(): void
    {
        MediaItem::factory()->create(['title' => 'Live', 'is_active' => true]);
        MediaItem::factory()->create(['title' => 'Disabled', 'is_active' => false]);

        $response = $this->getJson('/api/v1/display-schedule');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Live');
    }

    public function test_it_excludes_items_outside_their_daypart_window(): void
    {
        MediaItem::factory()->create(['title' => 'Not yet', 'starts_at' => now()->addDay()]);
        MediaItem::factory()->create(['title' => 'Expired', 'ends_at' => now()->subDay()]);
        MediaItem::factory()->create(['title' => 'Currently on air', 'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);

        $response = $this->getJson('/api/v1/display-schedule');

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.title', 'Currently on air');
    }

    public function test_it_trims_the_payload_to_the_kiosk_shape(): void
    {
        MediaItem::factory()->create();

        $response = $this->getJson('/api/v1/display-schedule');

        $response->assertJsonStructure([
            'data' => [
                ['id', 'type', 'title', 'url', 'body', 'duration', 'order', 'checksum'],
            ],
            'meta' => ['generated_at', 'poll_after_seconds'],
        ]);

        // Never leaks the internal auto-increment id or admin-only fields
        // (starts_at/ends_at/is_active) — the kiosk shape is deliberately
        // flatter than the admin one.
        $response->assertJsonMissingPath('data.0.is_active');
        $response->assertJsonMissingPath('data.0.starts_at');
    }

    public function test_a_matching_if_none_match_returns_304_with_no_body(): void
    {
        MediaItem::factory()->create();

        $first = $this->getJson('/api/v1/display-schedule');
        $etag = $first->headers->get('ETag');

        $second = $this->getJson('/api/v1/display-schedule', ['If-None-Match' => $etag]);

        $second->assertStatus(304);
        $second->assertNoContent(304);
    }

    public function test_a_stale_if_none_match_returns_a_fresh_200(): void
    {
        MediaItem::factory()->create();

        $first = $this->getJson('/api/v1/display-schedule');
        $staleEtag = $first->headers->get('ETag');

        // Any change to the on-air set changes the ETag's fingerprint —
        // a stale client should be handed the new data, not a 304.
        MediaItem::factory()->create();

        $second = $this->getJson('/api/v1/display-schedule', ['If-None-Match' => $staleEtag]);

        $second->assertOk();
        $second->assertJsonCount(2, 'data');
        $this->assertNotEquals($staleEtag, $second->headers->get('ETag'));
    }
}
