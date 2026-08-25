<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Admin\DeviceResource;
use App\Http\Resources\Admin\MediaItemResource;
use App\Models\MediaItem;
use App\Models\PlayerDevice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * "Current content playing" can't be tracked per screen — this app has
 * no player_device_id on media_items, only one shared on-air set (see
 * MediaItem::onAir(), also what App\Http\Controllers\Api\V1\
 * DisplayScheduleController::index() serves to every player) — so this
 * returns that shared playlist alongside the screen's own health, rather
 * than fabricating a per-screen "now playing" that doesn't exist.
 */
#[Name('get_screen_status')]
#[Description(
    "Gets one screen's connectivity/health status, plus the content currently on-air fleet-wide "
    .'(this app has no per-screen content assignment, so there is no screen-specific "now playing").'
)]
class GetScreenStatusTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'screen_id' => ['required', 'integer'],
        ]);

        $screen = PlayerDevice::with('latestHeartbeat')->find($validated['screen_id']);

        if (! $screen) {
            return Response::error("No screen found with id [{$validated['screen_id']}].");
        }

        return Response::structured([
            'screen' => (new DeviceResource($screen))->resolve(),
            'on_air_content' => MediaItemResource::collection(MediaItem::onAir()->get())->resolve(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'screen_id' => $schema->integer()
                ->description('The numeric id of the screen (PlayerDevice) to look up.')
                ->required(),
        ];
    }
}
