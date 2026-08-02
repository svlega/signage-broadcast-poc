<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only health samples. Deliberately NOT updated in place —
     * a time series is what lets ops see a slow memory-creep on a
     * 24/7-running Chromium tab (the classic embedded-web failure mode)
     * before it OOM-kills the player. Prune with a scheduled command in
     * production; this PoC keeps everything for demo purposes.
     */
    public function up(): void
    {
        Schema::create('player_heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('player_device_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('memory_used_mb');
            $table->unsignedInteger('memory_limit_mb')->nullable();
            $table->unsignedBigInteger('uptime_seconds');
            $table->string('app_version', 32)->nullable();

            // 'healthy' | 'degraded' | 'critical' — set client-side from
            // its own memory/uptime thresholds so triage doesn't require
            // the dashboard to know every device's hardware ceiling.
            $table->string('status', 16)->default('healthy');

            $table->timestamps();

            $table->index(['player_device_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_heartbeats');
    }
};
