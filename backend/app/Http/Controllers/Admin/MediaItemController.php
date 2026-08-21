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
            'checksum' => $this->freshChecksum(),
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
            'checksum' => $this->freshChecksum(),
        ]);

        return new MediaItemResource($mediaItem);
    }

    /**
     * `checksum` exists so the kiosk player can cache-bust its request
     * for an item's media without needing to know or care *why* it
     * changed (see frontend/src/utils/mediaUrl.ts). This app never
     * fetches the remote media itself — `url` just points at wherever
     * an admin says the file lives — so there's no byte content here to
     * actually hash, the way the Google Doc sync path can hash real
     * synced text. A fresh token minted on every explicit save is the
     * honest equivalent for a URL this app doesn't control the origin
     * of: it can't prove the remote file changed, but it also never
     * needs to — always invalidating on save means the player only ever
     * risks one redundant re-fetch of an *unchanged* file, never risks
     * serving a *stale* one.
     */
    private function freshChecksum(): string
    {
        return sha1((string) Str::uuid());
    }

    public function destroy(MediaItem $mediaItem): Response
    {
        $mediaItem->delete();

        return response()->noContent();
    }
}
