<?php

namespace Tests\Feature;

use App\Models\GoogleIntegration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncGoogleAnnouncementCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_skips_gracefully_when_not_connected(): void
    {
        $this->artisan('signage:sync-google-announcement')
            ->assertExitCode(0)
            ->expectsOutputToContain('not connected');
    }

    public function test_it_skips_gracefully_when_no_document_is_configured(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->artisan('signage:sync-google-announcement')
            ->assertExitCode(0)
            ->expectsOutputToContain('No Google Doc configured');
    }

    public function test_it_syncs_when_connected_and_configured(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
            'document_id' => 'doc-abc',
        ]);

        Http::fake([
            'docs.googleapis.com/*' => Http::response([
                'title' => 'Announcement',
                'body' => ['content' => [
                    ['paragraph' => ['elements' => [['textRun' => ['content' => "Hello.\n"]]]]],
                ]],
            ]),
        ]);

        $this->artisan('signage:sync-google-announcement')
            ->assertExitCode(0)
            ->expectsOutputToContain('Synced Google Doc doc-abc');

        $this->assertDatabaseHas('media_items', ['title' => 'Announcement', 'body' => 'Hello.']);
    }

    public function test_it_exits_with_a_failure_code_when_the_sync_throws(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
            'document_id' => 'doc-abc',
        ]);

        Http::fake(['docs.googleapis.com/*' => Http::response(['error' => 'nope'], 500)]);

        $this->artisan('signage:sync-google-announcement')
            ->assertExitCode(1);

        $this->assertNotNull(GoogleIntegration::current()->last_error);
    }
}
