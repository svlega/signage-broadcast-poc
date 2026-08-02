<?php

namespace Database\Factories;

use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaItem>
 */
class MediaItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'type' => 'slide',
            'title' => fake()->catchPhrase(),
            'url' => 'https://picsum.photos/seed/'.fake()->uuid().'/1920/1080',
            'body' => null,
            'duration_seconds' => 10,
            'sort_order' => 0,
            'is_active' => true,
            'checksum' => sha1(fake()->uuid()),
        ];
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'type' => 'video',
            'url' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
            'duration_seconds' => 0, // ignored by the player; plays to `ended`
        ]);
    }

    public function ticker(): static
    {
        return $this->state(fn () => [
            'type' => 'ticker',
            'url' => null,
            'body' => fake()->sentence(12),
            'duration_seconds' => 20,
        ]);
    }
}
