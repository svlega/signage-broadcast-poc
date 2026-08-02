<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\DeviceResource;
use App\Models\PlayerDevice;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DeviceController extends Controller
{
    /**
     * Fleet view: every enrolled screen plus its most recent health
     * sample. `with('latestHeartbeat')` avoids an N+1 — one query for
     * devices, one query for their latest heartbeats, regardless of
     * fleet size.
     */
    public function index(): AnonymousResourceCollection
    {
        return DeviceResource::collection(
            PlayerDevice::with('latestHeartbeat')->orderByDesc('last_seen_at')->get(),
        );
    }
}
