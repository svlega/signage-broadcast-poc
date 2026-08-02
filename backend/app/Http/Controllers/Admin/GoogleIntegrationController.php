<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\GoogleIntegration;
use App\Services\Google\GoogleAnnouncementSyncer;
use App\Services\Google\GoogleOAuthClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class GoogleIntegrationController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->status(GoogleIntegration::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Google Doc URLs put the id between /d/ and /edit — asking
            // for the raw id (not the full URL) keeps this endpoint from
            // having to parse several URL shapes just to extract it.
            'document_id' => ['required', 'string', 'max:255'],
        ]);

        $integration = GoogleIntegration::current();
        $integration->update($validated);

        return response()->json($this->status($integration));
    }

    /**
     * Full-page redirect, not a fetch: OAuth's consent screen is Google's
     * own page, which nothing on this origin can render inside an XHR.
     */
    public function connect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('google_oauth_state', $state);

        return redirect()->away(GoogleOAuthClient::fromConfig()->buildAuthorizationUrl($state));
    }

    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('google_oauth_state');

        // Guards against a CSRF-forged callback: without this check, an
        // attacker could trick an admin's browser into completing an
        // OAuth grant for an attacker-controlled Google account, wiring
        // this signage estate's ticker to content they control.
        if (! $state || $state !== $request->query('state')) {
            return redirect('/admin/integrations')->with('google_error', 'Invalid OAuth state.');
        }

        $code = $request->query('code');
        if (! $code) {
            return redirect('/admin/integrations')->with('google_error', 'Google did not return an authorization code.');
        }

        try {
            $tokens = GoogleOAuthClient::fromConfig()->exchangeCodeForTokens($code);

            $integration = GoogleIntegration::current();
            $integration->forceFill([
                'access_token' => $tokens['access_token'],
                // Google only returns a refresh_token on the first
                // consent grant for a given client+scope combination —
                // keep the existing one on a re-connect rather than
                // overwriting it with an absent value.
                'refresh_token' => $tokens['refresh_token'] ?? $integration->refresh_token,
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            return redirect('/admin/integrations')->with('google_error', $e->getMessage());
        }

        return redirect('/admin/integrations');
    }

    public function sync(GoogleAnnouncementSyncer $syncer): JsonResponse
    {
        $integration = GoogleIntegration::current();

        try {
            $syncer->sync($integration);
        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                ...$this->status($integration->refresh()),
            ], $e instanceof RuntimeException ? 422 : 500);
        }

        return response()->json($this->status($integration->refresh()));
    }

    public function disconnect(): JsonResponse
    {
        $integration = GoogleIntegration::current();
        $integration->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'token_expires_at' => null,
            'last_error' => null,
        ])->save();

        return response()->json($this->status($integration));
    }

    /**
     * @return array<string, mixed>
     */
    private function status(GoogleIntegration $integration): array
    {
        return [
            'connected' => $integration->isConnected(),
            'document_id' => $integration->document_id,
            'media_item_id' => $integration->media_item_id,
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
            'last_error' => $integration->last_error,
        ];
    }
}
