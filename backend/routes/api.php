<?php

use App\Http\Controllers\Api\V1\DisplayScheduleController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use Illuminate\Support\Facades\Route;

// Versioned from day one — a fleet of devices in the field cannot all be
// upgraded atomically, so `/v1` will keep serving unmodified once a `/v2`
// response shape is needed for newer player builds.
Route::prefix('v1')->group(function () {
    Route::get('/display-schedule', [DisplayScheduleController::class, 'index']);
    Route::post('/heartbeat', [HeartbeatController::class, 'store']);
});
