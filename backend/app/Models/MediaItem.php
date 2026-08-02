<?php

namespace App\Models;

use App\Events\ScheduleUpdated;
use Database\Factories\MediaItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaItem extends Model
{
    /** @use HasFactory<MediaItemFactory> */
    use HasFactory;

    /**
     * Any create/update/delete re-broadcasts the whole on-air set. This is
     * what turns "an admin edited a slide in the CMS" into "every screen
     * updates within a second" instead of "every screen updates on its
     * next 30s poll" — the gap that matters for time-sensitive content
     * like a recalled promotion or an emergency announcement.
     */
    protected static function booted(): void
    {
        static::saved(fn () => event(new ScheduleUpdated));
        static::deleted(fn () => event(new ScheduleUpdated));
    }

    protected $fillable = [
        'uuid', 'type', 'title', 'url', 'body',
        'duration_seconds', 'sort_order',
        'starts_at', 'ends_at', 'is_active', 'checksum',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Restricts the schedule to items that are both flagged active AND
     * inside their daypart window. Evaluated in SQL (not pulled to PHP and
     * filtered in memory) because a signage estate can have thousands of
     * items across all screens — the query should return only what a
     * single player actually needs to render.
     */
    public function scopeOnAir(Builder $query): Builder
    {
        $now = now();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->orderBy('sort_order');
    }
}
