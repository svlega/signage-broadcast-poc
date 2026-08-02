<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately flat and minimal. Every key removed here is a key the
 * player never has to parse — on a 24/7 loop that re-fetches the
 * schedule on an interval, JSON.parse() cost and payload size are not
 * free, they compound over thousands of cycles a week on hardware with
 * a fraction of a desktop's memory bandwidth.
 */
class MediaItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // UUID, not the DB id — the frontend's IndexedDB cache and
            // Service Worker asset store both key on this, so it must
            // stay stable even if rows are ever re-seeded.
            'id' => $this->uuid,
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
            'body' => $this->body,
            'duration' => $this->duration_seconds,
            'order' => $this->sort_order,
            'checksum' => $this->checksum,
        ];
    }
}
