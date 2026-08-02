<?php

namespace App\Providers;

use App\Services\Google\GoogleOAuthClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // GoogleOAuthClient's constructor takes plain config values, not
        // injectable classes — the container can't auto-wire those, so
        // this binding is what lets both the admin controller and the
        // scheduled sync command resolve GoogleAnnouncementSyncer (which
        // depends on it) via type-hinting alone, instead of every call
        // site having to know to call ::fromConfig() itself.
        $this->app->bind(GoogleOAuthClient::class, fn () => GoogleOAuthClient::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
