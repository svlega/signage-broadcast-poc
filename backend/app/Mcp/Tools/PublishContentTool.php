<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Admin\MediaItemResource;
use App\Mcp\Tools\Concerns\PublishesMediaItems;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('publish_content')]
#[Description(
    'Publishes a content item live to the entire signage fleet, immediately. '
    .'There is no per-screen or location-group targeting in this app — every screen shares one '
    .'playlist, so this affects what all of them show. Pass uuid to update and republish an '
    .'existing item; omit it to create a new one.'
)]
class PublishContentTool extends Tool
{
    use PublishesMediaItems;

    public function handle(Request $request): Response|ResponseFactory
    {
        // The tool never exposes is_active as a param — publishing always
        // means "live" — so it's merged in before validating rather than
        // asked of the caller.
        $request->merge(['is_active' => true]);

        $validated = $request->validate($this->baseRules());

        $result = $this->createOrUpdateMediaItem([
            ...$validated,
            // Explicit, not just "absent from $validated": publishing an
            // item that was previously scheduled for later (or that had
            // an end date) must clear that window so it's actually live
            // now, not silently still excluded by MediaItem::onAir().
            'starts_at' => null,
            'ends_at' => null,
        ], $request->get('uuid'));

        if ($result instanceof Response) {
            return $result;
        }

        return Response::structured([
            'media_item' => (new MediaItemResource($result))->resolve(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return $this->contentFieldsSchema($schema);
    }
}
