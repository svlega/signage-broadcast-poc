<?php

use App\Http\Controllers\Api\V1\DisplayScheduleController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use Illuminate\Support\Facades\Route;

// Versioned from day one — a fleet of devices in the field cannot all be
// upgraded atomically, so `/v1` will keep serving unmodified once a `/v2`
// response shape is needed for newer player builds.
Route::prefix('v1')->group(function () {
    Route::get('/display-schedule', [DisplayScheduleController::class, 'index']);
    // Delta sync for a device with a local manifest — see sync()'s own
    // docblock. Deliberately a separate route from index() above rather
    // than an optional ?since= on it: the two return genuinely different
    // response shapes (a flat item array vs. {updated, deleted,
    // server_time}), and conflating them behind one endpoint would make
    // that contract harder to see than the extra route costs.
    Route::get('/display-schedule/sync', [DisplayScheduleController::class, 'sync']);
    Route::post('/heartbeat', [HeartbeatController::class, 'store']);
});
