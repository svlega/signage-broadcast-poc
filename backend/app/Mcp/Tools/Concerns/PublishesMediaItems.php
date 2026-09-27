<?php

namespace App\Mcp\Tools\Concerns;

use App\Http\Requests\Admin\StoreMediaItemRequest;
use App\Models\MediaItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

/**
 * Shared by PublishContentTool and ScheduleContentTool — both are "create
 * or update a MediaItem, targeted by an optional uuid", differing only in
 * how they treat starts_at/ends_at. Kept out of both tool classes so that
 * difference is the only thing left in each one.
 */
trait PublishesMediaItems
{
    /**
     * The same field constraints
     * App\Http\Controllers\Admin\MediaItemController's store()/update()
     * already validate against — reused, not re-typed, so a rule change
     * there (e.g. tightening title's max length) can't quietly drift out
     * of sync with what an MCP client is allowed to send.
     *
     * @return array<string, mixed>
     */
    protected function baseRules(): array
    {
        return (new StoreMediaItemRequest)->rules();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createOrUpdateMediaItem(array $attributes, ?string $uuid): MediaItem|Response
    {
        $mediaItem = $uuid ? MediaItem::where('uuid', $uuid)->first() : null;

        if ($uuid && ! $mediaItem) {
            return Response::error("No content item found with uuid [{$uuid}].");
        }

        $attributes['checksum'] = MediaItem::mintChecksum();

        return $mediaItem
            ? tap($mediaItem)->update($attributes)
            : MediaItem::create([...$attributes, 'uuid' => (string) Str::uuid()]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function contentFieldsSchema(JsonSchema $schema): array
    {
        return [
            'uuid' => $schema->string()
                ->description('UUID of an existing content item to update. Omit to create a new item.'),
            'type' => $schema->string()
                ->enum(['video', 'slide', 'ticker'])
                ->description('The kind of content.')
                ->required(),
            'title' => $schema->string()
                ->description('Short title for the item.')
                ->required(),
            'url' => $schema->string()
                ->description('Absolute media URL. Required when type is video or slide.'),
            'body' => $schema->string()
                ->description('Ticker text. Required when type is ticker.'),
            'duration_seconds' => $schema->integer()
                ->min(0)
                ->default(10)
                ->description('How long to hold this slide/ticker on screen, in seconds. Ignored for video.'),
            'sort_order' => $schema->integer()
                ->min(0)
                ->default(0)
                ->description('Render order within the playlist.'),
        ];
    }
}
