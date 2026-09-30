<?php

namespace Kirbydesk\Translatewizard;

use Kirby\Http\Remote;
use RuntimeException;

/**
 * Minimal DeepL API client. Handles the auth-key based free/pro
 * endpoint switch and batches an array of texts into one request
 * (DeepL supports up to 50 text entries per POST).
 *
 * We only need the /translate endpoint. Non-2xx responses are turned
 * into RuntimeException with the DeepL message when available.
 */
final class DeepL
{
    private const BATCH_LIMIT = 50;

    public function __construct(
        private readonly string $apiKey,
    ) {
    }

    /**
     * @param list<string> $texts
     * @return list<string> Translated texts, same order as input.
     */
    public function translate(array $texts, string $targetLang, ?string $sourceLang = null): array
    {
        if ($texts === []) return [];

        $endpoint = str_ends_with($this->apiKey, ':fx')
            ? 'https://api-free.deepl.com/v2/translate'
            : 'https://api.deepl.com/v2/translate';

        // Split HTML-flavoured payloads (writer's <p>…</p>) from plain
        // text so DeepL uses tag_handling only where it helps. Plain
        // strings sent with tag_handling=html come back with & → &amp;
        // and quotes escaped — bad for pwtext values.
        $htmlIdx  = [];
        $plainIdx = [];
        foreach ($texts as $i => $t) {
            if (self::containsHtml($t)) {
                $htmlIdx[] = $i;
            } else {
                $plainIdx[] = $i;
            }
        }

        $translated = array_fill(0, count($texts), '');
        foreach ([
            ['idx' => $htmlIdx,  'tag_handling' => 'html'],
            ['idx' => $plainIdx, 'tag_handling' => null],
        ] as $group) {
            if ($group['idx'] === []) continue;
            $chunkTexts = array_map(fn ($i) => $texts[$i], $group['idx']);
            $results    = $this->post($endpoint, $chunkTexts, $targetLang, $sourceLang, $group['tag_handling']);
            foreach ($group['idx'] as $j => $origIdx) {
                $translated[$origIdx] = $results[$j] ?? '';
            }
        }

        return $translated;
    }

    /**
     * @param list<string> $chunk
     * @return list<string>
     */
    private function post(string $endpoint, array $chunk, string $targetLang, ?string $sourceLang, ?string $tagHandling): array
    {
        $out = [];
        foreach (array_chunk($chunk, self::BATCH_LIMIT) as $batch) {
            $body = [
                'text'        => $batch,
                'target_lang' => strtoupper($targetLang),
            ];
            if ($tagHandling !== null) {
                $body['tag_handling'] = $tagHandling;
            }
            if ($sourceLang !== null) {
                $body['source_lang'] = strtoupper($sourceLang);
            }

            $response = Remote::request($endpoint, [
                'method'  => 'POST',
                'data'    => json_encode($body),
                'headers' => [
                    'Authorization: DeepL-Auth-Key ' . $this->apiKey,
                    'Content-Type: application/json',
                ],
            ]);

            if ($response->code() < 200 || $response->code() >= 300) {
                $msg = $response->content() ?: 'HTTP ' . $response->code();
                throw new RuntimeException('DeepL error: ' . $msg);
            }

            $decoded = json_decode($response->content(), true);
            if (!is_array($decoded) || !isset($decoded['translations'])) {
                throw new RuntimeException('DeepL: malformed response');
            }

            foreach ($decoded['translations'] as $t) {
                $out[] = $t['text'] ?? '';
            }
        }

        return $out;
    }

    /**
     * The account's usage this billing period: characters translated and
     * the limit (DeepL's /v2/usage).
     *
     * @return array{count: int, limit: int}
     */
    public function usage(): array
    {
        $endpoint = str_ends_with($this->apiKey, ':fx')
            ? 'https://api-free.deepl.com/v2/usage'
            : 'https://api.deepl.com/v2/usage';

        $response = Remote::request($endpoint, [
            'method'  => 'GET',
            'headers' => ['Authorization: DeepL-Auth-Key ' . $this->apiKey],
        ]);
        if ($response->code() < 200 || $response->code() >= 300) {
            throw new RuntimeException('DeepL error: ' . ($response->content() ?: 'HTTP ' . $response->code()));
        }
        $data = json_decode($response->content(), true);
        if (!is_array($data) || !isset($data['character_count'], $data['character_limit'])) {
            throw new RuntimeException('DeepL: malformed response');
        }
        return ['count' => (int) $data['character_count'], 'limit' => (int) $data['character_limit']];
    }

    private static function containsHtml(string $text): bool
    {
        return preg_match('/<[a-z][a-z0-9]*\b[^>]*>/i', $text) === 1;
    }
}
