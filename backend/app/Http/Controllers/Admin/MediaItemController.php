<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreMediaItemRequest;
use App\Http\Requests\Admin\UpdateMediaItemRequest;
use App\Http\Resources\Admin\MediaItemResource;
use App\Models\MediaItem;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class MediaItemController extends Controller
{
    /**
     * Every item regardless of is_active/daypart window — unlike the
     * kiosk-facing onAir() scope, an admin needs to see (and re-enable)
     * content that isn't currently on air.
     */
    public function index(): AnonymousResourceCollection
    {
        return MediaItemResource::collection(MediaItem::orderBy('sort_order')->get());
    }

    public function store(StoreMediaItemRequest $request): MediaItemResource
    {
        $item = MediaItem::create([
            ...$request->validated(),
            'uuid' => (string) Str::uuid(),
            'checksum' => MediaItem::mintChecksum(),
        ]);

        return new MediaItemResource($item);
    }

    public function show(MediaItem $mediaItem): MediaItemResource
    {
        return new MediaItemResource($mediaItem);
    }

    public function update(UpdateMediaItemRequest $request, MediaItem $mediaItem): MediaItemResource
    {
        $mediaItem->update([
            ...$request->validated(),
            'checksum' => MediaItem::mintChecksum(),
        ]);

        return new MediaItemResource($mediaItem);
    }

    public function destroy(MediaItem $mediaItem): Response
    {
        $mediaItem->delete();

        return response()->noContent();
    }
}
