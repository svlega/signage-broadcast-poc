<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Signage Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{--
        The admin Vue app reads this to send X-CSRF-TOKEN on every
        mutating fetch (see resources/js/admin/api.ts). This is the
        classic same-origin Laravel SPA pattern — simpler than Sanctum's
        SPA cookie dance, and correct here specifically because the admin
        panel is served by this same Laravel app, not a separately
        hosted frontend like the kiosk player is.
    --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    @if (session('google_error'))
        {{--
            The Google OAuth callback (GoogleIntegrationController@callback)
            is a full-page redirect back to this same Blade shell — a
            session-flashed error is the only way for it to hand a message
            to the Vue app that boots fresh on that reload.
        --}}
        <meta name="google-oauth-error" content="{{ session('google_error') }}">
    @endif

    @vite(['resources/css/app.css', 'resources/js/admin/main.ts'])
</head>
<body>
    <div id="admin-app"></div>
</body>
</html>
