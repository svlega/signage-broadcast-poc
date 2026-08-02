<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerHeartbeat extends Model
{
    protected $fillable = [
        'player_device_id', 'memory_used_mb', 'memory_limit_mb',
        'uptime_seconds', 'app_version', 'status',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(PlayerDevice::class, 'player_device_id');
    }
}
