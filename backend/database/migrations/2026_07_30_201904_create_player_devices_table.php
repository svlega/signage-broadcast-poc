<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per physical screen in the fleet. `device_uid` is generated
     * client-side on first boot and persisted to LocalStorage so a factory
     * reset / SD-card swap doesn't silently spawn a duplicate device row.
     */
    public function up(): void
    {
        Schema::create('player_devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_uid')->unique();
            $table->string('name')->nullable();
            $table->string('location')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Heartbeats hammer this table every 15-30s from every screen
            // in the fleet; last_seen_at is what the fleet dashboard
            // polls, so it needs its own index rather than relying on the
            // primary key.
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_devices');
    }
};
