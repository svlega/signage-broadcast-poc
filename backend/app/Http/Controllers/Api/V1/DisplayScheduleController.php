<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;

class DisplayScheduleController extends Controller
{
    /**
     * GET /api/v1/display-schedule
     *
     * Serves the current on-air playlist. Two low-power-specific choices
     * here matter more than the endpoint's simplicity suggests:
     *
     * 1. ETag / 304: signage players poll this endpoint on an interval
     *    (see useSignageSocket's fallback polling) for weeks at a time.
     *    Most polls return an *unchanged* schedule. A 304 with no body
     *    costs the SoC nothing to parse and costs the network nothing to
     *    transfer, versus re-sending and re-deserializing the same JSON
     *    array every single poll.
     *
     * 2. `onAir()` scope filtering happens in SQL, not in PHP after
     *    fetching everything — the player should only ever receive the
     *    small slice of media it needs to render right now, not the
     *    organization's entire content library.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $items = MediaItem::onAir()->get();

        $etag = $this->computeEtag($items);

        if ($request->headers->get('If-None-Match') === $etag) {
            return response()->noContent(304)->header('ETag', $etag);
        }

        return MediaItemResource::collection($items)
            ->additional([
                'meta' => [
                    'generated_at' => now()->toIso8601String(),
                    // Tells the player how long it may trust this
                    // response before polling again, so cadence lives in
                    // one place (the server) instead of being hardcoded
                    // on every device in the fleet.
                    'poll_after_seconds' => 30,
                ],
            ])
            ->response()
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, max-age=30');
    }

    private function computeEtag(Collection $items): string
    {
        $fingerprint = $items->map(fn (MediaItem $item) => $item->uuid.$item->updated_at?->timestamp)->implode('|');

        return 'W/"'.md5($fingerprint).'"';
    }
}
