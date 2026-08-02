<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Media items are the atomic units of a signage broadcast: a video, an
     * image slide, or a scrolling ticker announcement. Kept intentionally
     * flat (no polymorphic slide-builder tables) because the player only
     * ever loops over a small flat array — the flatter the API payload,
     * the less JSON parsing / object allocation the SoC has to do on
     * every schedule refresh.
     */
    public function up(): void
    {
        Schema::create('media_items', function (Blueprint $table) {
            $table->id();

            // Public, stable identifier the player caches against in
            // IndexedDB. Never reuse/renumber — the offline cache keys on
            // this, not on the incrementing id.
            $table->uuid('uuid')->unique();

            $table->enum('type', ['video', 'slide', 'ticker']);
            $table->string('title');

            // Absolute, CDN-friendly URL. The player fetches this once and
            // caches the bytes; we never proxy media through the API.
            $table->string('url')->nullable();

            // Ticker items carry their message inline instead of a media URL.
            $table->text('body')->nullable();

            // How long to hold this slide/ticker on screen, in seconds.
            // Videos ignore this and play to their natural `ended` event.
            $table->unsignedInteger('duration_seconds')->default(10);

            // Render order within the active schedule.
            $table->unsignedInteger('sort_order')->default(0);

            // Simple dayparting so a schedule can be pre-loaded and the
            // player itself decides what's "on air" right now without
            // another round trip.
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();

            $table->boolean('is_active')->default(true);

            // SHA-1 of the remote asset, so the player's offline cache can
            // skip re-downloading unchanged media after a reconnect.
            $table->string('checksum', 64)->nullable();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_items');
    }
};
