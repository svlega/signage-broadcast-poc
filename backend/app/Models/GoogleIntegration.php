<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleIntegration extends Model
{
    protected $fillable = [
        'access_token', 'refresh_token', 'token_expires_at',
        'document_id', 'media_item_id', 'last_synced_at', 'last_error',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function mediaItem(): BelongsTo
    {
        return $this->belongsTo(MediaItem::class);
    }

    public function isConnected(): bool
    {
        return ! empty($this->refresh_token);
    }

    public function tokenIsExpired(): bool
    {
        return $this->token_expires_at === null || $this->token_expires_at->isPast();
    }

    /**
     * Singleton accessor: this PoC has exactly one Google connection for
     * the whole signage estate, so every caller — the OAuth callback,
     * the sync command, the admin status endpoint — reads and writes the
     * same row rather than threading a tenant/user id through all of
     * them for a distinction that doesn't exist yet.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
