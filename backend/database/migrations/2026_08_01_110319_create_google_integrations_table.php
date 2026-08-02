<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single-row table by convention (see GoogleIntegration::current()) —
     * this PoC connects one Google Workspace account for the whole org,
     * not per-admin-user credentials. A real multi-tenant version would
     * key this on organization/team instead.
     */
    public function up(): void
    {
        Schema::create('google_integrations', function (Blueprint $table) {
            $table->id();

            // Encrypted at the model layer (Eloquent's `encrypted` cast,
            // keyed off APP_KEY) — an OAuth refresh token is a standing
            // credential to someone's Google Workspace account and must
            // never sit in the database in plaintext.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // The Google Doc synced into the ticker. An admin sets this
            // after connecting; nothing syncs until it's present.
            $table->string('document_id')->nullable();

            // Nullable FK, not required: no MediaItem exists until the
            // first successful sync creates one.
            $table->foreignId('media_item_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('last_synced_at')->nullable();
            // Surfaced in the admin UI so a broken sync (expired grant,
            // deleted doc, doc shared with the wrong account) is visible
            // there instead of only in the Laravel log.
            $table->text('last_error')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_integrations');
    }
};
