<?php

namespace Tests\Feature;

use App\Events\ScheduleUpdated;
use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ScheduleBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_media_item_broadcasts_a_schedule_update(): void
    {
        Event::fake([ScheduleUpdated::class]);

        MediaItem::factory()->create();

        Event::assertDispatched(ScheduleUpdated::class);
    }

    public function test_updating_a_media_item_broadcasts_a_schedule_update(): void
    {
        $item = MediaItem::factory()->create();

        Event::fake([ScheduleUpdated::class]);
        $item->update(['title' => 'Changed']);

        Event::assertDispatched(ScheduleUpdated::class);
    }

    public function test_deleting_a_media_item_broadcasts_a_schedule_update(): void
    {
        $item = MediaItem::factory()->create();

        Event::fake([ScheduleUpdated::class]);
        $item->delete();

        Event::assertDispatched(ScheduleUpdated::class);
    }

    public function test_the_broadcast_payload_contains_only_the_current_on_air_set(): void
    {
        MediaItem::factory()->create(['title' => 'On air', 'is_active' => true]);
        MediaItem::factory()->create(['title' => 'Not on air', 'is_active' => false]);

        // Constructed directly rather than captured off a save(): the
        // point of this test is broadcastWith()'s own onAir() filtering,
        // independent of which write triggered the event.
        $payload = (new ScheduleUpdated)->broadcastWith();

        $titles = array_column($payload['items'], 'title');

        $this->assertContains('On air', $titles);
        $this->assertNotContains('Not on air', $titles);
    }
}
