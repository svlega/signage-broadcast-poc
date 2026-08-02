<?php

use Illuminate\Support\Facades\Broadcast;

// `signage.schedule` (see App\Events\ScheduleUpdated) is a public
// Channel, so it needs no entry here — only Private/Presence channels
// go through this authorization callback.
//
// A production fleet would authenticate each screen with a per-device
// Sanctum token and scope pushes accordingly, e.g.:
//
// Broadcast::channel('signage.device.{deviceUid}', function ($device, string $deviceUid) {
//     return $device->device_uid === $deviceUid;
// });
