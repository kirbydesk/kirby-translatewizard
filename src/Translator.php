<?php

namespace Kirbydesk\Translatewizard;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;

/**
 * Translates a page's Blocks-field between two languages. Reads the
 * source-language content, walks all blocks (recursively into nested
 * blocks / buttons), collects translatable payloads, hands them to
 * DeepL in one batch, and writes the result back to the target
 * language.
 *
 * Non-block top-level fields (title, meta.*) are copied 1:1 for now —
 * they are outside the scope of pagewizard's block system and would
 * fit into a future Content-Translator interop layer.
 */
final class Translator
{
    public function __construct(
        private readonly DeepL $deepl,
    ) {
    }

    /**
     * Translate $page's Blocks field from $source to $target. Returns
     * the number of text units sent to DeepL. Writes result into the
     * target-language content version via Kirby's update() API.
     */
    public function translatePage(Page|Site $page, string $source, string $target): int
    {
        $sourceContent = $page->content($source)->toArray();

        $blocksRaw = $sourceContent['blocks'] ?? null;
        if (!is_string($blocksRaw)) {
            return 0;
        }

        $blocks = Data::decode($blocksRaw, 'json');
        if (!is_array($blocks) || $blocks === []) {
            return 0;
        }

        /** @var list<array{setValue: \Closure(string): void, encoded: array{text:string, token:?array}}> $units */
        $units = [];

        BlockWalker::walk($blocks, function (string $fieldName, mixed $value, \Closure $replace) use (&$units): void {
            if (!is_string($value) || $value === '') {
                return;
            }
            $decoded = PwtextCodec::decode($value);
            if (trim($decoded['text']) === '') {
                return;
            }
            // Only translate fields whose content is actually text.
            // Skip toggle values, colors, sizes, etc. — those are
            // short single-token strings that DeepL would garble.
            if ($decoded['token'] === null && !self::looksLikeSentence($decoded['text'])) {
                return;
            }

            $units[] = [
                'text'    => $decoded['text'],
                'token'   => $decoded['token'],
                'replace' => $replace,
            ];
        });

        if ($units === []) {
            // no translatable content — still copy the untranslated
            // blocks so target-language content mirrors source-language
            $this->writeBlocks($page, $blocks, $target);
            return 0;
        }

        $texts = array_map(fn ($u) => $u['text'], $units);
        $translated = $this->deepl->translate($texts, $target, $source);

        foreach ($units as $index => $unit) {
            $newText = $translated[$index] ?? $unit['text'];
            $unit['replace'](PwtextCodec::encode($newText, $unit['token']));
        }

        $this->writeBlocks($page, $blocks, $target);

        return count($units);
    }

    private function writeBlocks(Page|Site $page, array $blocks, string $target): void
    {
        $encoded = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $page->update(['blocks' => $encoded], $target);
    }

    /**
     * Heuristic: is $text likely a natural-language sentence (worth
     * translating), or a config-token like "large" / "left" / "h2"?
     *
     * We look for whitespace or a non-ASCII character. That catches
     * German diacritics ("Räume", "Übungen") and any phrase, while
     * rejecting single-word config values.
     */
    private static function looksLikeSentence(string $text): bool
    {
        if (str_contains($text, ' ')) return true;
        // any character above ASCII → likely real content
        return preg_match('/[^\x00-\x7F]/u', $text) === 1;
    }
}
