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

        $translated = [];
        foreach (array_chunk($texts, self::BATCH_LIMIT) as $chunk) {
            $body = [
                'text'        => $chunk,
                'target_lang' => strtoupper($targetLang),
                'tag_handling' => 'html',
            ];
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
                $translated[] = $t['text'] ?? '';
            }
        }

        return $translated;
    }
}
