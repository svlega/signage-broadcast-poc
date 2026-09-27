<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Admin\DeviceResource;
use App\Models\PlayerDevice;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Thin wrapper around exactly the query
 * App\Http\Controllers\Admin\DeviceController::index() already runs —
 * same eager-loaded latest heartbeat, same DeviceResource shape (which is
 * also where `is_online`'s 90-second-since-last-heartbeat rule lives).
 */
#[Name('list_screens')]
#[Description('Lists every screen enrolled in the signage fleet, with its location and online status.')]
class ListScreensTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $screens = PlayerDevice::with('latestHeartbeat')
            ->orderByDesc('last_seen_at')
            ->get();

        return Response::structured([
            'screens' => DeviceResource::collection($screens)->resolve(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
