<?php

namespace Database\Seeders;

use App\Models\MediaItem;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class MediaItemSeeder extends Seeder
{
    /**
     * Static demo content, not `MediaItem::factory()`: the factory uses
     * `fake()` (fakerphp/faker, require-dev-only), and this seeder needs
     * to run in the `--no-dev` production/Docker image too — that's the
     * one place a fresh reviewer would actually run it to get something
     * on screen. `MediaItem::factory()` itself is untouched and still
     * uses Faker freely; it's meant for tests, which install dev deps.
     */
    public function run(): void
    {
        // Guards against duplicating demo content: the Docker `migrate`
        // service runs `db:seed` on every `docker compose up`, not just
        // the first one, so this needs to be safe to call repeatedly —
        // and it doubles as not clobbering real content an admin has
        // since added.
        if (MediaItem::query()->exists()) {
            return;
        }

        MediaItem::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'ticker',
            'title' => 'Weather Alert',
            'body' => 'Severe thunderstorm warning in effect until 8:00 PM.',
            'duration_seconds' => 20,
            'sort_order' => 0,
            'is_active' => true,
            'checksum' => sha1('weather-alert'),
        ]);

        $slides = [
            'Welcome to the Lobby',
            'Q3 All-Hands: Friday 10 AM',
            'New Employee Benefits Portal Live',
            'Recycling Bins Now on Every Floor',
        ];

        foreach ($slides as $index => $title) {
            MediaItem::query()->create([
                'uuid' => (string) Str::uuid(),
                'type' => 'slide',
                'title' => $title,
                'url' => 'https://picsum.photos/seed/signage-slide-'.($index + 1).'/1920/1080',
                'duration_seconds' => 10,
                'sort_order' => $index + 1,
                'is_active' => true,
                'checksum' => sha1($title),
            ]);
        }

        MediaItem::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'video',
            'title' => 'Brand Promo Reel',
            'url' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
            'duration_seconds' => 0, // ignored by the player; plays to `ended`
            'sort_order' => 5,
            'is_active' => true,
            'checksum' => sha1('brand-promo-reel'),
        ]);
    }
}
