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

    public function test_sync_with_no_since_returns_the_full_on_air_set_and_no_deletions(): void
    {
        MediaItem::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/display-schedule/sync');

        $response->assertOk();
        $response->assertJsonCount(2, 'updated');
        $response->assertJson(['deleted' => []]);
        $response->assertJsonStructure(['updated', 'deleted', 'server_time']);
    }

    public function test_sync_returns_only_items_updated_after_since(): void
    {
        $this->travelTo(now()->subMinutes(10));
        MediaItem::factory()->create(['title' => 'Already known']);

        $since = now()->toIso8601String();

        $this->travelTo(now()->addMinute());
        MediaItem::factory()->create(['title' => 'New since last sync']);

        $response = $this->getJson('/api/v1/display-schedule/sync?since='.urlencode($since));

        $response->assertOk();
        $response->assertJsonCount(1, 'updated');
        $response->assertJsonPath('updated.0.title', 'New since last sync');
    }

    public function test_sync_reports_a_deleted_item_by_uuid(): void
    {
        $item = MediaItem::factory()->create();
        $since = now()->toIso8601String();

        $this->travelTo(now()->addMinute());
        $item->delete();

        $response = $this->getJson('/api/v1/display-schedule/sync?since='.urlencode($since));

        $response->assertOk();
        $response->assertJson(['deleted' => [$item->uuid]]);
        // Deleted, so it must not also show up as "updated" — the two
        // lists are meant to be mutually exclusive.
        $response->assertJsonCount(0, 'updated');
    }

    public function test_sync_does_not_report_deletions_that_happened_before_since(): void
    {
        $item = MediaItem::factory()->create();
        $item->delete();

        $since = now()->toIso8601String();

        // An unrelated change after $since, so this test exercises the
        // delta query's own deletion-filtering logic specifically —
        // without it, nothing would have happened since $since at all,
        // and the 304 fast path (tested separately below) would answer
        // first, before the query this test means to check ever runs.
        $this->travelTo(now()->addMinute());
        MediaItem::factory()->create(['title' => 'Unrelated later change']);

        $response = $this->getJson('/api/v1/display-schedule/sync?since='.urlencode($since));

        $response->assertOk();
        $response->assertJson(['deleted' => []]);
    }

    public function test_sync_returns_304_when_nothing_has_changed_since_the_watermark(): void
    {
        MediaItem::factory()->create();
        $since = now()->toIso8601String();

        $response = $this->getJson('/api/v1/display-schedule/sync?since='.urlencode($since));

        $response->assertStatus(304);
        $response->assertNoContent(304);
    }

    public function test_sync_does_not_return_304_when_an_item_changed_after_the_watermark(): void
    {
        $item = MediaItem::factory()->create();
        $since = now()->toIso8601String();

        $this->travelTo(now()->addMinute());
        $item->update(['title' => 'Changed after since']);

        $response = $this->getJson('/api/v1/display-schedule/sync?since='.urlencode($since));

        $response->assertOk();
    }

    public function test_sync_does_not_return_304_when_the_only_change_is_a_deletion(): void
    {
        // Deletions are the trickier path: the 304 check has to notice
        // them via `withTrashed()->max('updated_at')`, not just plain
        // `updated_at` on the active rows the delta query itself reads —
        // an easy thing to get wrong by checking only one and not both.
        $item = MediaItem::factory()->create();
        $since = now()->toIso8601String();

        $this->travelTo(now()->addMinute());
        $item->delete();

        $response = $this->getJson('/api/v1/display-schedule/sync?since='.urlencode($since));

        $response->assertOk();
        $response->assertJson(['deleted' => [$item->uuid]]);
    }

    public function test_sync_never_returns_304_on_a_first_sync_with_no_since(): void
    {
        // No watermark means no prior state to compare against — the
        // 304 short-circuit is keyed entirely on `since` being present,
        // so it must never trigger here regardless of how static the
        // content library is.
        $response = $this->getJson('/api/v1/display-schedule/sync');

        $response->assertOk();
    }

    public function test_sync_with_no_since_never_reports_deletions(): void
    {
        // A device with no manifest yet has nothing for a deletion to
        // remove — first-sync semantics, matching index()'s "everything
        // on-air is new" behavior.
        $item = MediaItem::factory()->create();
        $item->delete();

        $response = $this->getJson('/api/v1/display-schedule/sync');

        $response->assertOk();
        $response->assertJson(['deleted' => []]);
    }

    public function test_sync_rejects_an_invalid_since_value(): void
    {
        $response = $this->getJson('/api/v1/display-schedule/sync?since=not-a-date');

        $response->assertUnprocessable();
    }
}
