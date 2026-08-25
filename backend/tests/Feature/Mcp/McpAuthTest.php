<?php

namespace Tests\Feature\Mcp;

use Tests\TestCase;

class McpAuthTest extends TestCase
{
    /**
     * A minimal-but-real JSON-RPC envelope (an MCP "initialize" call) —
     * the point of these tests is the auth layer, not the protocol
     * layer, so this only needs to be well-formed enough that a rejected
     * request is provably rejected by EnsureValidMcpToken and not by
     * some other 4xx the transport would also return for a bad token.
     */
    private function initializePayload(): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-client', 'version' => '0.0.1'],
            ],
        ];
    }

    public function test_it_rejects_a_request_with_no_bearer_token(): void
    {
        $response = $this->postJson('/mcp/signage', $this->initializePayload());

        $response->assertUnauthorized();
    }

    public function test_it_rejects_a_request_with_the_wrong_bearer_token(): void
    {
        $response = $this->withToken('the-wrong-token')
            ->postJson('/mcp/signage', $this->initializePayload());

        $response->assertUnauthorized();
    }

    public function test_it_accepts_a_request_with_the_configured_dev_token(): void
    {
        config(['services.mcp.token' => 'test-dev-token']);

        $response = $this->withToken('test-dev-token')
            ->postJson('/mcp/signage', $this->initializePayload());

        // Not asserting a specific 2xx here — the point is that auth let
        // it through to the MCP transport at all, which a 401 would
        // prove it didn't. The transport's own response shape is the MCP
        // package's concern, already covered by its own test suite.
        $response->assertStatus(200);
    }

    public function test_it_rejects_when_no_dev_token_is_configured_at_all(): void
    {
        // A misconfigured deployment (blank MCP_DEV_TOKEN) must fail
        // closed, not silently accept every request because
        // hash_equals('', '') would otherwise be true for an empty
        // presented token.
        config(['services.mcp.token' => null]);

        $response = $this->withToken('anything')
            ->postJson('/mcp/signage', $this->initializePayload());

        $response->assertUnauthorized();
    }
}
