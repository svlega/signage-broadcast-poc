<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\SignageServer;
use App\Mcp\Tools\GetScreenStatusTool;
use App\Mcp\Tools\ListScreensTool;
use App\Mcp\Tools\PublishContentTool;
use App\Mcp\Tools\ScheduleContentTool;
use App\Mcp\Tools\SummarizeUpdatesForSignageTool;
use App\Models\MediaItem;
use App\Models\PlayerDevice;
use App\Models\PlayerHeartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

class SignageServerToolsTest extends TestCase
{
    use RefreshDatabase;

    private function makeScreen(array $overrides = []): PlayerDevice
    {
        return PlayerDevice::create(array_merge([
            'device_uid' => 'screen-'.uniqid(),
            'name' => 'Lobby Screen',
            'location' => 'HQ Lobby',
            'last_seen_at' => now(),
        ], $overrides));
    }

    /**
     * assertStructuredContent() only accepts an exact-match array or a
     * fluent-assertion closure — neither gives back a plain array to run
     * ordinary PHPUnit assertions against. Capturing it via the closure's
     * ->toArray() and satisfying the fluent API's "did you assert
     * everything" check with ->etc() gets a raw array back out without
     * fighting AssertableJson's API for every assertion below.
     *
     * @return array<string, mixed>
     */
    private function structuredContentOf(TestResponse $response): array
    {
        $captured = null;

        $response->assertStructuredContent(function (AssertableJson $json) use (&$captured) {
            $captured = $json->toArray();

            return $json->etc();
        });

        return $captured;
    }

    public function test_list_screens_returns_every_screen_with_online_status(): void
    {
        $this->makeScreen(['name' => 'Online Screen', 'last_seen_at' => now()]);
        $this->makeScreen(['name' => 'Offline Screen', 'last_seen_at' => now()->subMinutes(10)]);

        $response = SignageServer::tool(ListScreensTool::class);

        $response->assertOk();

        $screens = $this->structuredContentOf($response)['screens'];
        $this->assertCount(2, $screens);

        $byName = collect($screens)->keyBy('name');
        $this->assertTrue($byName['Online Screen']['is_online']);
        $this->assertFalse($byName['Offline Screen']['is_online']);
    }

    public function test_get_screen_status_returns_health_and_the_shared_on_air_playlist(): void
    {
        $screen = $this->makeScreen();
        PlayerHeartbeat::create([
            'player_device_id' => $screen->id,
            'memory_used_mb' => 200,
            'memory_limit_mb' => 512,
            'uptime_seconds' => 1000,
            'app_version' => '1.0.0',
            'status' => 'healthy',
        ]);
        MediaItem::factory()->create(['is_active' => true, 'title' => 'On Air Slide']);
        MediaItem::factory()->create(['is_active' => false, 'title' => 'Inactive Slide']);

        $response = SignageServer::tool(GetScreenStatusTool::class, ['screen_id' => $screen->id]);

        $response->assertOk();

        $content = $this->structuredContentOf($response);
        $this->assertSame($screen->id, $content['screen']['id']);
        $this->assertSame('healthy', $content['screen']['latest_heartbeat']['status']);

        $titles = collect($content['on_air_content'])->pluck('title');
        $this->assertContains('On Air Slide', $titles);
        $this->assertNotContains('Inactive Slide', $titles);
    }

    public function test_get_screen_status_errors_for_an_unknown_screen_id(): void
    {
        $response = SignageServer::tool(GetScreenStatusTool::class, ['screen_id' => 999_999]);

        $response->assertHasErrors();
    }

    public function test_publish_content_creates_a_new_item_live_immediately(): void
    {
        $response = SignageServer::tool(PublishContentTool::class, [
            'type' => 'slide',
            'title' => 'New Promo',
            'url' => 'https://example.com/promo.jpg',
            'duration_seconds' => 15,
            'sort_order' => 0,
        ]);

        $response->assertOk();

        $item = $this->structuredContentOf($response)['media_item'];
        $this->assertSame('New Promo', $item['title']);
        $this->assertTrue($item['is_active']);
        $this->assertNull($item['starts_at']);
        $this->assertNotEmpty($item['checksum']);

        $this->assertDatabaseHas('media_items', ['title' => 'New Promo', 'is_active' => true]);
    }

    public function test_publish_content_updates_an_existing_item_by_uuid_and_mints_a_new_checksum(): void
    {
        $item = MediaItem::factory()->create([
            'title' => 'Old Title',
            'checksum' => 'original-checksum',
        ]);

        $response = SignageServer::tool(PublishContentTool::class, [
            'uuid' => $item->uuid,
            'type' => 'slide',
            'title' => 'Updated Title',
            'url' => 'https://example.com/updated.jpg',
            'duration_seconds' => 10,
            'sort_order' => 0,
        ]);

        $response->assertOk();

        $this->assertSame(1, MediaItem::query()->count());
        $item->refresh();
        $this->assertSame('Updated Title', $item->title);
        $this->assertNotSame('original-checksum', $item->checksum);
    }

    public function test_publish_content_clears_a_previous_schedule_so_the_item_is_actually_live_now(): void
    {
        // An item scheduled for tomorrow — MediaItem::onAir() excludes it
        // today. Publishing it must clear that window, not just flip
        // is_active, or it would stay invisible despite "publishing" it.
        $item = MediaItem::factory()->create([
            'is_active' => true,
            'starts_at' => now()->addDay(),
        ]);
        $this->assertFalse(MediaItem::onAir()->whereKey($item->id)->exists());

        SignageServer::tool(PublishContentTool::class, [
            'uuid' => $item->uuid,
            'type' => $item->type,
            'title' => $item->title,
            'url' => $item->url,
            'duration_seconds' => $item->duration_seconds,
            'sort_order' => $item->sort_order,
        ])->assertOk();

        $this->assertTrue(MediaItem::onAir()->whereKey($item->id)->exists());
    }

    public function test_publish_content_errors_when_the_uuid_does_not_match_any_item(): void
    {
        $response = SignageServer::tool(PublishContentTool::class, [
            'uuid' => 'not-a-real-uuid',
            'type' => 'slide',
            'title' => 'Whatever',
            'url' => 'https://example.com/a.jpg',
            'duration_seconds' => 10,
            'sort_order' => 0,
        ]);

        $response->assertHasErrors();
        $this->assertSame(0, MediaItem::query()->count());
    }

    public function test_schedule_content_requires_a_starts_at(): void
    {
        $response = SignageServer::tool(ScheduleContentTool::class, [
            'type' => 'slide',
            'title' => 'Future Promo',
            'url' => 'https://example.com/promo.jpg',
            'duration_seconds' => 10,
            'sort_order' => 0,
        ]);

        $response->assertHasErrors();
    }

    public function test_schedule_content_rejects_a_starts_at_in_the_past(): void
    {
        $response = SignageServer::tool(ScheduleContentTool::class, [
            'type' => 'slide',
            'title' => 'Too Late',
            'url' => 'https://example.com/promo.jpg',
            'duration_seconds' => 10,
            'sort_order' => 0,
            'starts_at' => now()->subHour()->toIso8601String(),
        ]);

        $response->assertHasErrors();
    }

    public function test_schedule_content_creates_an_item_that_is_not_yet_on_air(): void
    {
        $startsAt = now()->addDay();

        $response = SignageServer::tool(ScheduleContentTool::class, [
            'type' => 'slide',
            'title' => 'Tomorrow Promo',
            'url' => 'https://example.com/promo.jpg',
            'duration_seconds' => 10,
            'sort_order' => 0,
            'starts_at' => $startsAt->toIso8601String(),
        ]);

        $response->assertOk();

        $item = MediaItem::query()->where('title', 'Tomorrow Promo')->firstOrFail();
        $this->assertTrue($item->is_active);
        $this->assertFalse(MediaItem::onAir()->whereKey($item->id)->exists());
    }

    public function test_summarize_updates_for_signage_returns_recent_items_newest_first(): void
    {
        MediaItem::factory()->create(['title' => 'Old Item', 'updated_at' => now()->subDays(3)]);
        MediaItem::factory()->create(['title' => 'Newest Item', 'updated_at' => now()]);
        MediaItem::factory()->create(['title' => 'Middle Item', 'updated_at' => now()->subDay()]);

        $response = SignageServer::tool(SummarizeUpdatesForSignageTool::class, ['limit' => 2]);

        $response->assertOk();

        $titles = collect($this->structuredContentOf($response)['recent_updates'])->pluck('title');
        $this->assertSame(['Newest Item', 'Middle Item'], $titles->all());
    }

    public function test_summarize_updates_for_signage_defaults_to_five_items(): void
    {
        MediaItem::factory()->count(8)->create();

        $response = SignageServer::tool(SummarizeUpdatesForSignageTool::class);

        $response->assertOk();
        $this->assertCount(5, $this->structuredContentOf($response)['recent_updates']);
    }
}
