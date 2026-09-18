<?php

namespace Kirbydesk\Translatewizard;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;
use Throwable;

/**
 * Translates a page between two languages. Reads the source-language
 * content, collects translatable payloads — the Blocks field (walked
 * recursively into nested blocks / buttons), the title and pagewizard's
 * meta fields — hands them to DeepL in one batch, and writes the result
 * back to the target language. Pages also get a slug derived from the
 * translated title.
 */
final class Translator
{
    /** Plain text fields, translated as a whole. */
    private const TEXT_FIELDS = [
        'title',
        'metapagetitle',
        'metanavigationtitle',
        'metateaser',
        'metadescription',
    ];

    /** Tags fields (comma-separated), translated tag by tag. */
    private const TAG_FIELDS = [
        'metakeywords',
    ];

    public function __construct(
        private readonly DeepL $deepl,
    ) {
    }

    /**
     * Translate $page from $source to $target. Returns the number of
     * text units sent to DeepL. Writes the result into the
     * target-language content version via Kirby's update() API.
     */
    public function translatePage(Page|Site $page, string $source, string $target): int
    {
        $sourceContent = $page->content($source)->toArray();

        /** @var list<string> $texts */
        $texts = [];

        // ---- Blocks ----
        $blocks = null;
        /** @var list<array{path: list<int|string>, token: ?array, index: int}> $blockSlots */
        $blockSlots = [];

        $blocksRaw = $sourceContent['blocks'] ?? null;
        if (is_string($blocksRaw)) {
            $decodedBlocks = Data::decode($blocksRaw, 'json');
            if (is_array($decodedBlocks) && $decodedBlocks !== []) {
                $blocks = $decodedBlocks;

                // Uniform array-tree — nested blocks become sub-arrays, not JSON strings.
                BlockWalker::decodeAll($blocks);

                foreach (BlockWalker::collect($blocks) as $visit) {
                    $value = $visit['value'];
                    if (!is_string($value) || $value === '') continue;

                    $decoded = PwtextCodec::decode($value);
                    if (trim($decoded['text']) === '') continue;

                    // Skip config tokens (single-word ascii like "large", "left",
                    // "h2"). Real content usually has whitespace or non-ASCII.
                    if ($decoded['token'] === null && !self::looksLikeSentence($decoded['text'])) {
                        continue;
                    }

                    $blockSlots[] = ['path' => $visit['path'], 'token' => $decoded['token'], 'index' => count($texts)];
                    $texts[]      = $decoded['text'];
                }
            }
        }

        // ---- Title + meta text fields ----
        /** @var array<string, int> $fieldSlots field => index in $texts */
        $fieldSlots = [];
        foreach (self::TEXT_FIELDS as $field) {
            $value = $sourceContent[$field] ?? null;
            if (!is_string($value) || trim($value) === '') continue;

            $fieldSlots[$field] = count($texts);
            $texts[]            = $value;
        }

        // ---- Tags fields ----
        /** @var array<string, list<int>> $tagSlots field => indexes in $texts */
        $tagSlots = [];
        foreach (self::TAG_FIELDS as $field) {
            $value = $sourceContent[$field] ?? null;
            if (!is_string($value)) continue;

            $tags = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($t) => $t !== ''));
            foreach ($tags as $tag) {
                $tagSlots[$field][] = count($texts);
                $texts[]            = $tag;
            }
        }

        $translated = $this->deepl->translate($texts, $target, $source);
        $result     = fn (int $i) => $translated[$i] ?? $texts[$i];

        $update = [];

        if ($blocks !== null) {
            foreach ($blockSlots as $slot) {
                $newValue = PwtextCodec::encode($result($slot['index']), $slot['token']);
                BlockWalker::setByPath($blocks, $slot['path'], $newValue);
            }

            // Written even without translatable units, so target-language
            // content mirrors the source-language block structure.
            BlockWalker::encodeAll($blocks);
            $update['blocks'] = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        foreach ($fieldSlots as $field => $index) {
            $update[$field] = $result($index);
        }

        foreach ($tagSlots as $field => $indexes) {
            $update[$field] = implode(', ', array_map($result, $indexes));
        }

        if ($update !== []) {
            $page = $page->update($update, $target);
        }

        if (isset($fieldSlots['title'])) {
            $this->translateSlug($page, $result($fieldSlots['title']), $target);
        }

        return count($texts);
    }

    /**
     * Derive the target-language slug from the translated title — only
     * on the first translation. Once the language has its own slug, it
     * stays: the URL may already be published, linked or indexed.
     * Home and error page keep their slug — Kirby resolves them by it.
     * A slug collision with a sibling is not fatal: the page simply
     * keeps its current slug.
     */
    private function translateSlug(Page|Site $page, string $title, string $target): void
    {
        if (!$page instanceof Page) return;
        if ($page->isHomeOrErrorPage()) return;
        if ($page->slug($target) !== $page->uid()) return;

        try {
            $page->changeSlug($title, $target);
        } catch (Throwable) {
            // keep current slug
        }
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
