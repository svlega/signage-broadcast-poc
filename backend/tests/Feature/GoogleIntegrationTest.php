<?php

namespace Tests\Feature;

use App\Models\GoogleIntegration;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function fakeDocsResponse(string $title, array $paragraphs): array
    {
        return [
            'title' => $title,
            'body' => [
                'content' => array_map(
                    fn (string $text) => [
                        'paragraph' => [
                            'elements' => [
                                ['textRun' => ['content' => $text."\n"]],
                            ],
                        ],
                    ],
                    $paragraphs,
                ),
            ],
        ];
    }

    public function test_connect_redirects_to_google_and_stores_a_state_in_session(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->get('/admin/integrations/google/connect');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://accounts.google.com/o/oauth2/v2/auth',
            $response->headers->get('Location'),
        );
        $this->assertNotNull(session('google_oauth_state'));
    }

    public function test_callback_rejects_a_mismatched_state(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->withSession(['google_oauth_state' => 'expected-state'])
            ->get('/admin/integrations/google/callback?state=wrong-state&code=abc123');

        $response->assertRedirect('/admin/integrations');
        $response->assertSessionHas('google_error');
        $this->assertFalse(GoogleIntegration::current()->isConnected());
    }

    public function test_callback_exchanges_the_code_and_stores_tokens(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access-token',
                'refresh_token' => 'fresh-refresh-token',
                'expires_in' => 3600,
            ]),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->withSession(['google_oauth_state' => 'matching-state'])
            ->get('/admin/integrations/google/callback?state=matching-state&code=authcode');

        $response->assertRedirect('/admin/integrations');

        $integration = GoogleIntegration::current();
        $this->assertTrue($integration->isConnected());
        $this->assertSame('fresh-access-token', $integration->access_token);
        $this->assertFalse($integration->tokenIsExpired());
    }

    public function test_status_endpoint_reports_connection_state(): void
    {
        GoogleIntegration::current()->update([
            'refresh_token' => 'rt',
            'document_id' => 'doc-abc',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->getJson('/admin/api/integrations/google');

        $response->assertOk();
        $response->assertJson(['connected' => true, 'document_id' => 'doc-abc']);
    }

    public function test_update_sets_the_document_id(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->putJson('/admin/api/integrations/google', ['document_id' => 'my-doc-id']);

        $response->assertOk();
        $this->assertSame('my-doc-id', GoogleIntegration::current()->document_id);
    }

    public function test_sync_creates_a_ticker_media_item_from_the_document(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'valid-token',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
            'document_id' => 'doc-abc',
        ]);

        Http::fake([
            'docs.googleapis.com/*' => Http::response(
                $this->fakeDocsResponse('Today\'s Announcement', ['All-hands at 3 PM.', 'Kitchen is closed for cleaning.']),
            ),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/admin/api/integrations/google/sync');

        $response->assertOk();
        $response->assertJson(['connected' => true]);

        $this->assertDatabaseHas('media_items', [
            'type' => 'ticker',
            'title' => "Today's Announcement",
            'body' => "All-hands at 3 PM.\nKitchen is closed for cleaning.",
        ]);

        $integration = GoogleIntegration::current();
        $this->assertNotNull($integration->last_synced_at);
        $this->assertNotNull($integration->media_item_id);
        $this->assertNull($integration->last_error);
    }

    public function test_sync_updates_the_same_media_item_on_repeat_syncs(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'valid-token',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
            'document_id' => 'doc-abc',
        ]);

        Http::fake([
            'docs.googleapis.com/*' => Http::sequence()
                ->push($this->fakeDocsResponse('Announcement', ['First version.']))
                ->push($this->fakeDocsResponse('Announcement', ['Updated version.'])),
        ]);

        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/admin/api/integrations/google/sync')->assertOk();
        $firstCount = MediaItem::query()->count();

        $this->actingAs($user)->postJson('/admin/api/integrations/google/sync')->assertOk();

        // One synced item updated in place, not a second row appended —
        // the ticker should show the latest doc content, not accumulate
        // a new "announcement" slide on every scheduled run.
        $this->assertSame($firstCount, MediaItem::query()->count());
        $this->assertDatabaseHas('media_items', ['body' => 'Updated version.']);
    }

    public function test_sync_refreshes_an_expired_access_token_before_fetching(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'stale-token',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->subMinute(),
            'document_id' => 'doc-abc',
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'refreshed-token',
                'expires_in' => 3600,
            ]),
            'docs.googleapis.com/*' => Http::response($this->fakeDocsResponse('Doc', ['Body.'])),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/admin/api/integrations/google/sync')
            ->assertOk();

        $this->assertSame('refreshed-token', GoogleIntegration::current()->access_token);

        Http::assertSent(fn ($request) => $request->url() === 'https://docs.googleapis.com/v1/documents/doc-abc'
            && $request->hasHeader('Authorization', 'Bearer refreshed-token'));
    }

    public function test_sync_records_an_error_when_the_document_fetch_fails(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'valid-token',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
            'document_id' => 'missing-doc',
        ]);

        Http::fake(['docs.googleapis.com/*' => Http::response(['error' => 'not found'], 404)]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/admin/api/integrations/google/sync');

        $response->assertStatus(422);
        $this->assertNotNull(GoogleIntegration::current()->last_error);
    }

    public function test_sync_fails_cleanly_when_no_document_is_configured(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'valid-token',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/admin/api/integrations/google/sync');

        $response->assertStatus(422);
    }

    public function test_disconnect_clears_tokens(): void
    {
        GoogleIntegration::current()->update([
            'access_token' => 'at',
            'refresh_token' => 'rt',
            'token_expires_at' => now()->addHour(),
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson('/admin/api/integrations/google/disconnect');

        $response->assertOk();
        $response->assertJson(['connected' => false]);
        $this->assertFalse(GoogleIntegration::current()->isConnected());
    }
}
