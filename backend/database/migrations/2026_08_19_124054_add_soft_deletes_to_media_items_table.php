<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plain `updated_at > since` query can't express "this was
     * removed" — a deleted row just isn't there to match against.
     * Soft-deleting instead of hard-deleting keeps the row (and its
     * `deleted_at`) around long enough for a delta-sync query to report
     * it as a deletion to devices that were offline when it happened;
     * see MediaItem::onAir() and DisplayScheduleController::sync().
     */
    public function up(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->softDeletes()->index();
        });
    }

    public function down(): void
    {
        Schema::table('media_items', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
