<?php

namespace App\Services\Google;

use App\Models\GoogleIntegration;
use App\Models\MediaItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Orchestrates one end of "pull today's announcement from a Google Doc
 * into the ticker": refreshes the stored OAuth token if it's expired,
 * fetches the configured Doc, and upserts the one MediaItem this
 * integration owns. Called from both the manual "Sync now" admin
 * action and the scheduled command (see routes/console.php) — the
 * scheduled path is what makes this an actual live integration rather
 * than a button that only works if someone remembers to click it.
 */
class GoogleAnnouncementSyncer
{
    public function __construct(
        private readonly GoogleOAuthClient $oauth,
        private readonly GoogleDocsClient $docs,
    ) {}

    public function sync(GoogleIntegration $integration): void
    {
        if (! $integration->isConnected()) {
            throw new RuntimeException('Google Workspace is not connected.');
        }

        if (! $integration->document_id) {
            throw new RuntimeException('No Google Doc is configured to sync from.');
        }

        try {
            $accessToken = $this->ensureFreshAccessToken($integration);

            $document = $this->docs->fetchPlainText($integration->document_id, $accessToken);

            $mediaItem = $this->upsertTickerItem($integration, $document);

            $integration->forceFill([
                'media_item_id' => $mediaItem->id,
                'last_synced_at' => Carbon::now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            // Recorded on the row (surfaced in the admin UI) rather than
            // just thrown: a scheduled sync failing at 3am should be
            // visible to whoever opens the dashboard next, not only to
            // whoever happens to be reading the Laravel log.
            $integration->forceFill(['last_error' => $e->getMessage()])->save();

            throw $e;
        }
    }

    private function ensureFreshAccessToken(GoogleIntegration $integration): string
    {
        if (! $integration->tokenIsExpired() && $integration->access_token) {
            return $integration->access_token;
        }

        $tokens = $this->oauth->refreshAccessToken($integration->refresh_token);

        $integration->forceFill([
            'access_token' => $tokens['access_token'],
            'token_expires_at' => Carbon::now()->addSeconds($tokens['expires_in']),
        ])->save();

        return $tokens['access_token'];
    }

    /**
     * @param  array{title: string, text: string}  $document
     */
    private function upsertTickerItem(GoogleIntegration $integration, array $document): MediaItem
    {
        $attributes = [
            'type' => 'ticker',
            'title' => $document['title'],
            'body' => $document['text'] !== '' ? $document['text'] : 'No content in this document yet.',
            'is_active' => true,
            'checksum' => sha1($document['text']),
        ];

        if ($integration->media_item_id && ($existing = MediaItem::find($integration->media_item_id))) {
            $existing->update($attributes);

            return $existing;
        }

        // First sync: no MediaItem exists yet for this integration, so
        // one is created rather than assuming an admin made one by hand
        // — the whole point is that this works with zero manual setup
        // beyond connecting the account and picking a document.
        return MediaItem::create([
            ...$attributes,
            'uuid' => (string) Str::uuid(),
            'duration_seconds' => 20,
            'sort_order' => 0,
        ]);
    }
}
