<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deletion specifically, not general CRUD: MediaItem::delete() changed
 * meaning (hard → soft) when the sync feature needed a way to report
 * removals to an offline device — this is what proves that change is
 * actually correct, not just that a row disappears from a response.
 */
class AdminMediaItemDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_media_item_soft_deletes_it(): void
    {
        $item = MediaItem::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->deleteJson("/admin/api/media-items/{$item->id}");

        $response->assertNoContent();

        // Still in the table (soft-deleted), not gone entirely — a hard
        // delete here would silently break the sync endpoint's ability
        // to ever report this removal to a device that was offline when
        // it happened.
        $this->assertSoftDeleted('media_items', ['id' => $item->id]);
    }

    public function test_a_deleted_item_no_longer_appears_in_the_admin_list(): void
    {
        $item = MediaItem::factory()->create();
        $item->delete();

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/admin/api/media-items');

        $response->assertOk();
        $response->assertJsonMissing(['id' => $item->id]);
    }
}
