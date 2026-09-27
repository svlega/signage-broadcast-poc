<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\MediaItemResource;
use App\Models\MediaItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
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

    /**
     * GET /api/v1/display-schedule/sync?since=<ISO8601>
     *
     * The delta counterpart to index() above. A player holding a local
     * manifest (content id + updated_at + checksum per item — see the
     * kiosk player's offline-cache composable) asks for only what
     * changed since its last successful sync, instead of re-fetching the
     * full on-air list every poll. This is what makes reconnecting after
     * an arbitrarily long offline window cheap: the response size
     * depends on how much actually changed, never on how large the
     * content library is.
     *
     * `since` omitted (a device with no manifest yet, i.e. first boot)
     * degrades to "everything currently on-air is new" on the exact same
     * query — there's no separate first-sync code path to keep in sync
     * with this one.
     *
     * 304 fast path: most polls find nothing changed, and computing that
     * "nothing" still costs two filtered queries plus a JSON body if done
     * the naive way. A plain `MAX(updated_at)` — one cheap indexed
     * aggregate, across `withTrashed()` so a deletion counts too, since
     * a soft-delete touches `updated_at` the same as any other write —
     * answers "has anything at all happened since this device's `since`"
     * without needing either of the real delta queries. This is
     * deliberately *not* an ETag/If-None-Match exchange: an ETag
     * fingerprints one canonical resource state, but this response is
     * parameterized by whatever `since` a given request sends, so a
     * cached ETag from one `since` could wrongly validate against a
     * later request with a different one (e.g. a device that's also
     * advanced its watermark via the WebSocket push in between polls).
     * Deriving the check from `since` itself — already part of every
     * request — sidesteps that entirely.
     */
    public function sync(Request $request): Response|JsonResponse
    {
        $validated = $request->validate([
            'since' => ['nullable', 'date'],
        ]);

        $since = isset($validated['since']) ? Carbon::parse($validated['since']) : null;

        if ($since) {
            $latestChange = MediaItem::withTrashed()->max('updated_at');

            if (! $latestChange || Carbon::parse($latestChange)->lessThanOrEqualTo($since)) {
                return response()->noContent(304);
            }
        }

        $updated = MediaItem::onAir()
            ->when($since, fn (Builder $query) => $query->where('updated_at', '>', $since))
            ->get();

        // A plain `updated_at > since` query can't express "this row is
        // gone" — a deleted item just isn't there to match against. The
        // media_items table is soft-deleted (see MediaItem's SoftDeletes
        // trait) specifically so a query against `deleted_at` can report
        // removals the same way `updated_at` reports changes. Only
        // meaningful once a device already has a manifest to remove
        // something *from* — with no `since`, nothing has been sent yet
        // for a deletion to apply against.
        $deleted = $since
            ? MediaItem::onlyTrashed()->where('deleted_at', '>', $since)->pluck('uuid')
            : collect();

        return response()->json([
            'updated' => MediaItemResource::collection($updated)->resolve(),
            'deleted' => $deleted->values(),
            // The device stores *this*, not its own clock, as the
            // watermark for its next sync — comparing two different
            // clocks (server vs. device) across an offline gap of
            // unknown length is exactly the class of drift bug this
            // sidesteps entirely.
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
