<?php

namespace App\Console\Commands;

use App\Models\GoogleIntegration;
use App\Services\Google\GoogleAnnouncementSyncer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('signage:sync-google-announcement')]
#[Description('Pull the configured Google Doc into the announcement ticker')]
class SyncGoogleAnnouncement extends Command
{
    /**
     * Scheduled (see routes/console.php) so the ticker updates on its
     * own after someone edits the Doc — the "Sync now" button in the
     * admin UI calls the exact same GoogleAnnouncementSyncer, just on
     * demand instead of on a timer.
     */
    public function handle(GoogleAnnouncementSyncer $syncer): int
    {
        $integration = GoogleIntegration::current();

        if (! $integration->isConnected()) {
            $this->comment('Google Workspace is not connected — skipping.');

            return self::SUCCESS;
        }

        if (! $integration->document_id) {
            $this->comment('No Google Doc configured to sync from — skipping.');

            return self::SUCCESS;
        }

        try {
            $syncer->sync($integration);
        } catch (Throwable $e) {
            $this->error("Google Doc sync failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Synced Google Doc {$integration->document_id} into media item #{$integration->media_item_id}.");

        return self::SUCCESS;
    }
}
