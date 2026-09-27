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

#[Name('schedule_content')]
#[Description(
    'Schedules a content item for a future time on the whole signage fleet — there is no '
    .'per-screen or location-group targeting in this app, so this affects every screen once '
    .'it goes live. The item is created/updated now but MediaItem::onAir() keeps it off-air '
    .'until starts_at. Pass uuid to reschedule an existing item.'
)]
class ScheduleContentTool extends Tool
{
    use PublishesMediaItems;

    public function handle(Request $request): Response|ResponseFactory
    {
        $request->merge(['is_active' => true]);

        $rules = [
            ...$this->baseRules(),
            // The base rules leave starts_at nullable (it's an optional
            // dayparting field for direct admin edits) — scheduling is
            // meaningless without one, so this tool tightens it to
            // required and in the future.
            'starts_at' => ['required', 'date', 'after:now'],
        ];

        $validated = $request->validate($rules);

        $result = $this->createOrUpdateMediaItem($validated, $request->get('uuid'));

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
        return [
            ...$this->contentFieldsSchema($schema),
            'starts_at' => $schema->string()
                ->description('ISO 8601 date-time this item goes live, e.g. 2026-08-25T09:00:00Z.')
                ->required(),
            'ends_at' => $schema->string()
                ->description('Optional ISO 8601 date-time this item goes off-air again.'),
        ];
    }
}
