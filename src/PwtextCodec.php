<?php

namespace Kirbydesk\Translatewizard;

/**
 * Extracts translatable text from pagewizard's custom field types
 * (pwtext, pweditor) and writes it back after translation. Both types
 * store JSON with configuration alongside the actual text.
 *
 *   pwtext:   {"text":"…","align":"left","level":"h2","size":"lg"}
 *   pweditor: {"mode":"writer","align":"left","size":"lg",
 *              "textarea":"","writer":"<p>…</p>","markdown":""}
 *
 * decode() returns the translatable payload plus a token that encode()
 * uses to rebuild the JSON envelope. A raw (non-JSON) value passes
 * through untouched — callers can hand any field value to decode().
 */
final class PwtextCodec
{
    /**
     * @return array{text: string, token: array<string, mixed>|null}
     */
    public static function decode(string $raw): array
    {
        $trim = ltrim($raw);
        if ($trim === '' || $trim[0] !== '{') {
            return ['text' => $raw, 'token' => null];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['text' => $raw, 'token' => null];
        }

        if (isset($data['mode'])) {
            $mode = $data['mode'];
            if (in_array($mode, ['writer', 'textarea', 'markdown'], true) && array_key_exists($mode, $data)) {
                return [
                    'text'  => (string) $data[$mode],
                    'token' => ['kind' => 'pweditor', 'envelope' => $data, 'slot' => $mode],
                ];
            }
            return ['text' => $raw, 'token' => null];
        }

        if (array_key_exists('text', $data)) {
            return [
                'text'  => (string) $data['text'],
                'token' => ['kind' => 'pwtext', 'envelope' => $data],
            ];
        }

        return ['text' => $raw, 'token' => null];
    }

    /**
     * Rebuild the field value from a translated text and the token
     * decode() returned. When token is null, the translation is the
     * literal field value (plain text / textarea / writer field).
     */
    public static function encode(string $translated, ?array $token): string
    {
        if ($token === null) {
            return $translated;
        }

        $envelope = $token['envelope'];
        $slot     = $token['kind'] === 'pweditor' ? $token['slot'] : 'text';
        $envelope[$slot] = $translated;

        return json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
