<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The kiosk player only busts its media cache when checksum changes
 * (see frontend/src/utils/mediaUrl.ts) — these lock in the half of that
 * contract this app is actually responsible for keeping: every admin
 * save mints a fresh checksum, so an edit is never invisible to the
 * player just because it happened through the dashboard instead of the
 * Google Doc sync path.
 */
class AdminMediaItemChecksumTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'slide',
            'title' => 'Test Slide',
            'url' => 'https://example.com/slide.jpg',
            'duration_seconds' => 10,
            'sort_order' => 0,
            'is_active' => true,
        ], $overrides);
    }

    public function test_creating_a_media_item_sets_a_checksum(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->postJson('/admin/api/media-items', $this->validPayload());

        $response->assertCreated();
        $response->assertJsonPath('data.checksum', fn ($checksum) => is_string($checksum) && $checksum !== '');
    }

    public function test_updating_a_media_item_changes_its_checksum(): void
    {
        $item = MediaItem::factory()->create(['checksum' => 'original-checksum']);

        $response = $this->actingAs(User::factory()->create())
            ->putJson("/admin/api/media-items/{$item->id}", $this->validPayload(['title' => 'Edited title']));

        $response->assertOk();
        $newChecksum = $response->json('data.checksum');

        $this->assertNotSame('original-checksum', $newChecksum);
        $this->assertNotEmpty($newChecksum);
    }

    public function test_two_separate_saves_produce_two_different_checksums(): void
    {
        // Not just "changed once" — every explicit save is its own new
        // version, so the player treats each one as a reason to
        // re-validate the media, not just the first edit after seeding.
        $item = MediaItem::factory()->create();
        $user = User::factory()->create();

        $first = $this->actingAs($user)
            ->putJson("/admin/api/media-items/{$item->id}", $this->validPayload(['title' => 'First edit']))
            ->json('data.checksum');

        $second = $this->actingAs($user)
            ->putJson("/admin/api/media-items/{$item->id}", $this->validPayload(['title' => 'Second edit']))
            ->json('data.checksum');

        $this->assertNotSame($first, $second);
    }
}
