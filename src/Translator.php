<?php

namespace Kirbydesk\Translatewizard;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;

/**
 * Translates a page's Blocks field between two languages. Reads the
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
        if (!is_string($blocksRaw)) return 0;

        $blocks = Data::decode($blocksRaw, 'json');
        if (!is_array($blocks) || $blocks === []) return 0;

        // Uniform array-tree — nested blocks become sub-arrays, not JSON strings.
        BlockWalker::decodeAll($blocks);

        $visits = BlockWalker::collect($blocks);

        /** @var list<array{path: list<int|string>, token: ?array}> $pending */
        $pending = [];
        $texts   = [];

        foreach ($visits as $visit) {
            $value = $visit['value'];
            if (!is_string($value) || $value === '') continue;

            $decoded = PwtextCodec::decode($value);
            if (trim($decoded['text']) === '') continue;

            // Skip config tokens (single-word ascii like "large", "left",
            // "h2"). Real content usually has whitespace or non-ASCII.
            if ($decoded['token'] === null && !self::looksLikeSentence($decoded['text'])) {
                continue;
            }

            $pending[] = ['path' => $visit['path'], 'token' => $decoded['token']];
            $texts[]   = $decoded['text'];
        }

        if ($texts === []) {
            // no translatable content — still copy the untranslated
            // blocks so target-language content mirrors source-language
            BlockWalker::encodeAll($blocks);
            $this->writeBlocks($page, $blocks, $target);
            return 0;
        }

        $translated = $this->deepl->translate($texts, $target, $source);

        foreach ($pending as $index => $slot) {
            $newText  = $translated[$index] ?? $texts[$index];
            $newValue = PwtextCodec::encode($newText, $slot['token']);
            BlockWalker::setByPath($blocks, $slot['path'], $newValue);
        }

        BlockWalker::encodeAll($blocks);
        $this->writeBlocks($page, $blocks, $target);

        return count($pending);
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
        return preg_match('/[^\x00-\x7F]/u', $text) === 1;
    }
}
