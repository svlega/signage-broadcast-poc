<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to Google's OAuth 2.0 endpoints directly over HTTP rather than
 * pulling in google/apiclient. That SDK wraps every Google API in one
 * package; this integration uses exactly two endpoints (token exchange
 * and Docs `documents.get`), so the SDK's weight buys nothing here and
 * a raw Http-facade client keeps the actual protocol — the part worth
 * understanding — visible instead of hidden behind a generated client.
 */
class GoogleOAuthClient
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly array $scopes,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (string) config('services.google.client_id'),
            (string) config('services.google.client_secret'),
            (string) config('services.google.redirect_uri'),
            config('services.google.scopes', []),
        );
    }

    public function buildAuthorizationUrl(string $state): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes),
            // offline + consent together are what actually guarantees a
            // refresh_token comes back. Google only issues one on the
            // *first* grant by default; without `prompt=consent` forcing
            // a re-grant, a re-connect after a revoked/lost token would
            // silently get an access token and no way to refresh it.
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in: int}
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google token exchange failed: '.$response->body());
        }

        return $response->json();
    }

    /**
     * @return array{access_token: string, expires_in: int}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Google token refresh failed: '.$response->body());
        }

        return $response->json();
    }
}
