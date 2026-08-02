<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreHeartbeatRequest extends FormRequest
{
    /**
     * A real fleet deployment would gate this behind a per-device Sanctum
     * token minted at provisioning time. Left open here to keep the PoC
     * runnable without a device-enrollment flow.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Generated on the device's first boot and persisted to
            // LocalStorage — stable across app reloads, distinct per
            // physical screen even if two devices share a network.
            'device_uid' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:120'],

            // Heap usage as read from performance.memory /
            // navigator.deviceMemory on the player. This is the single
            // most important signal for a 24/7 embedded tab: a slow
            // upward trend here is the leading indicator of an eventual
            // Chromium OOM-kill.
            'memory_used_mb' => ['required', 'integer', 'min:0'],
            'memory_limit_mb' => ['nullable', 'integer', 'min:0'],

            // Seconds since the player's JS runtime last booted. Resets
            // to near-zero after any crash/reload, which is itself a
            // useful reliability signal even without reading logs.
            'uptime_seconds' => ['required', 'integer', 'min:0'],

            'app_version' => ['nullable', 'string', 'max:32'],
            'status' => ['nullable', 'string', 'in:healthy,degraded,critical'],
        ];
    }
}
