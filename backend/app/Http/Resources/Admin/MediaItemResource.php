<?php

namespace App\Http\Resources\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately the full row, unlike the kiosk-facing
 * App\Http\Resources\MediaItemResource which trims every field the
 * player doesn't render. The admin panel is a normal desktop app doing
 * CRUD — there's no payload-size or parse-cost budget to protect here,
 * so the tradeoff that shapes the *other* resource doesn't apply to
 * this one.
 */
class MediaItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'title' => $this->title,
            'url' => $this->url,
            'body' => $this->body,
            'duration_seconds' => $this->duration_seconds,
            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'is_active' => $this->is_active,
            'checksum' => $this->checksum,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
