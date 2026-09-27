<?php

namespace App\Mcp\Tools;

use App\Http\Resources\Admin\MediaItemResource;
use App\Models\MediaItem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * Returns raw recent items rather than calling out to an LLM internally —
 * the calling AI client (Claude Desktop, a custom chatbot) already *is*
 * an LLM, so summarizing here too would mean paying for and maintaining
 * a second model call for output the client can produce itself from this
 * same data, with better context about how the summary will be used.
 */
#[Name('summarize_updates_for_signage')]
#[Description(
    'Returns the most recently created or updated content items, newest first, as raw data — '
    .'not a pre-written summary — so the calling AI client can phrase a signage-formatted summary itself.'
)]
class SummarizeUpdatesForSignageTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $items = MediaItem::query()
            ->orderByDesc('updated_at')
            ->limit($validated['limit'] ?? 5)
            ->get();

        return Response::structured([
            'recent_updates' => MediaItemResource::collection($items)->resolve(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->min(1)
                ->max(50)
                ->default(5)
                ->description('Maximum number of recent content items to return.'),
        ];
    }
}
