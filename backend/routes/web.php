<?php

use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\GoogleIntegrationController;
use App\Http\Controllers\Admin\MediaItemController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// No public marketing page in this app — just the API (routes/api.php),
// the admin backend, and a separately-hosted kiosk player. Laravel's
// stock welcome view was never wired up to anything real, so root just
// sends a visitor straight to the one place there's something to see;
// `auth` middleware on /admin bounces an unauthenticated visitor to
// /admin/login from there.
Route::get('/', fn () => redirect('/admin'));

Route::middleware('guest')->group(function () {
    Route::get('/admin/login', [AuthController::class, 'create'])->name('login');
    Route::post('/admin/login', [AuthController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('/admin/logout', [AuthController::class, 'destroy']);

    // Session-authenticated CRUD the admin Vue app calls. Deliberately
    // separate from routes/api.php's /api/v1/* — those are the
    // unauthenticated, cross-origin, CORS-enabled endpoints the kiosk
    // player polls; these live under the `web` middleware group so they
    // get session auth + CSRF for free, which is exactly what a
    // same-origin admin panel needs and the kiosk player must not have.
    Route::prefix('admin/api')->group(function () {
        // ->parameters(...) pins the route-model-binding wildcard to
        // `mediaItem`, matching the controller's parameter name exactly —
        // apiResource's default wildcard for a hyphenated resource name
        // like "media-items" is `media_item` (underscored), which would
        // otherwise silently fail to bind against a camelCase `$mediaItem`
        // argument.
        Route::apiResource('media-items', MediaItemController::class)
            ->parameters(['media-items' => 'mediaItem']);
        Route::get('devices', [DeviceController::class, 'index']);

        Route::prefix('integrations/google')->group(function () {
            Route::get('/', [GoogleIntegrationController::class, 'show']);
            Route::put('/', [GoogleIntegrationController::class, 'update']);
            Route::post('sync', [GoogleIntegrationController::class, 'sync']);
            Route::post('disconnect', [GoogleIntegrationController::class, 'disconnect']);
        });
    });

    // Full-page routes (a redirect to Google, and the redirect back) —
    // registered before the SPA catch-all below so they aren't swallowed
    // by it. `/admin/api/*` above never needs this: it's a fetch target,
    // not a page a browser navigates to. GOOGLE_REDIRECT_URI (see
    // .env.example) must match this callback path exactly — it's also
    // registered as an allowed redirect URI on the Google Cloud OAuth
    // client itself.
    Route::get('admin/integrations/google/connect', [GoogleIntegrationController::class, 'connect'])
        ->name('google.connect');
    Route::get('admin/integrations/google/callback', [GoogleIntegrationController::class, 'callback'])
        ->name('google.callback');

    // SPA catch-all: any /admin/* path (client-side vue-router routes
    // between Dashboard/Media Items/Devices/Integrations) resolves to
    // the same Blade shell, which boots the Vue app and lets vue-router
    // take over from the current URL.
    Route::get('/admin/{any?}', function () {
        return view('admin');
    })->where('any', '.*');
});
