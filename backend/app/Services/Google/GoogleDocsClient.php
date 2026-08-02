<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDocsClient
{
    private const API_URL = 'https://docs.googleapis.com/v1/documents/';

    /**
     * @return array{title: string, text: string}
     */
    public function fetchPlainText(string $documentId, string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::API_URL.$documentId);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch Google Doc {$documentId}: ".$response->body());
        }

        $document = $response->json();

        return [
            'title' => $document['title'] ?? 'Untitled document',
            'text' => $this->extractPlainText($document),
        ];
    }

    /**
     * Google Docs' JSON model represents a document as a tree of
     * structural elements (paragraphs, tables, tables-of-contents,
     * section breaks, ...), not plain text. This walks only the
     * paragraph → elements → textRun path — the shape a short "today's
     * announcement" doc actually is — and deliberately ignores tables,
     * images, and footnotes rather than trying to losslessly flatten
     * every element type the Docs API can return.
     *
     * @param  array<string, mixed>  $document
     */
    private function extractPlainText(array $document): string
    {
        $paragraphs = [];

        foreach ($document['body']['content'] ?? [] as $structuralElement) {
            $paragraph = $structuralElement['paragraph'] ?? null;
            if (! $paragraph) {
                continue;
            }

            $text = '';
            foreach ($paragraph['elements'] ?? [] as $element) {
                $text .= $element['textRun']['content'] ?? '';
            }

            $text = trim($text);
            if ($text !== '') {
                $paragraphs[] = $text;
            }
        }

        return implode("\n", $paragraphs);
    }
}
