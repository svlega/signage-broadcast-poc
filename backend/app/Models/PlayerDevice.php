<?php

namespace App\Models;

use Database\Factories\PlayerDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlayerDevice extends Model
{
    /** @use HasFactory<PlayerDeviceFactory> */
    use HasFactory;

    protected $fillable = ['device_uid', 'name', 'location', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(PlayerHeartbeat::class);
    }

    public function latestHeartbeat(): HasMany
    {
        return $this->heartbeats()->latest()->limit(1);
    }
}
