<?php

use App\Mcp\Servers\SignageServer;
use Laravel\Mcp\Facades\Mcp;

// HTTP-reachable server (MCP Inspector, a custom chatbot, any remote MCP
// client) — gated by a fixed dev bearer token, see
// App\Http\Middleware\EnsureValidMcpToken. Auto-registered by
// laravel/mcp's service provider because this file is named routes/ai.php
// — no entry needed in bootstrap/app.php's withRouting().
Mcp::web('/mcp/signage', SignageServer::class)
    ->middleware('mcp.token');

// stdio server for a locally-spawned client (Claude Desktop's `command`
// config) — no token needed, since the OS process boundary is the auth
// here: only whoever can already run `php artisan` on this machine can
// spawn it.
Mcp::local('signage', SignageServer::class);
