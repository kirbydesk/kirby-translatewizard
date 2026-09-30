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
 * translated title. Which fields count: their type in the blueprints and
 * the choice in the Project Wizard (see Fields).
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

    /** A dry run: the texts that would go to DeepL (nothing is sent or written). */
    public array $sent = [];

    public function __construct(
        private readonly ?DeepL $deepl,
        private readonly bool $dryRun = false,
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
        $reencode = [];
        /** @var list<array{path: list<int|string>, token: ?array, index: int}> $blockSlots */
        $blockSlots = [];

        $blocksRaw = $sourceContent['blocks'] ?? null;
        if (is_string($blocksRaw)) {
            $decodedBlocks = Data::decode($blocksRaw, 'json');
            if (is_array($decodedBlocks) && $decodedBlocks !== []) {
                $blocks = $decodedBlocks;

                // Uniform array-tree — nested blocks become sub-arrays, not JSON strings.
                BlockWalker::decodeAll($blocks);

                // which fields: their type in the block's blueprint and the
                // choice in the Project Wizard (Fields)
                $reencode = [];
                foreach (BlockWalker::collect($blocks) as $visit) {
                    $fields = Fields::ofBlock($visit['type']);
                    $field  = $visit['field'];
                    $value  = $visit['value'];

                    // a block without blueprint: only pagewizard's own text
                    // fields (their JSON envelope says so)
                    if ($fields === null) {
                        if (!is_string($value) || $value === '') continue;
                        $decoded = PwtextCodec::decode($value);
                        if ($decoded['token'] === null || trim($decoded['text']) === '') continue;
                        $blockSlots[] = ['path' => $visit['path'], 'token' => $decoded['token'], 'index' => count($texts)];
                        $texts[]      = $decoded['text'];
                        continue;
                    }
                    if (!isset($fields[$field])) continue;

                    // a structure: its text columns, row by row
                    if ($fields[$field]['type'] === 'structure') {
                        $rows = is_string($value) ? json_decode($value, true) : $value;
                        if (!is_array($rows) || !array_is_list($rows)) continue;
                        if (is_string($value)) {
                            BlockWalker::setByPath($blocks, $visit['path'], $rows);
                            $reencode[] = $visit['path'];
                        }
                        foreach ($rows as $r => $row) {
                            if (!is_array($row)) continue;
                            foreach (array_keys($fields[$field]['columns']) as $col) {
                                if (!Fields::enabled($visit['type'], $field . '.' . $col)) continue;
                                $cell = $row[$col] ?? null;
                                if (!is_string($cell) || trim($cell) === '') continue;
                                $decoded = PwtextCodec::decode($cell);
                                if (trim($decoded['text']) === '') continue;
                                $blockSlots[] = ['path' => [...$visit['path'], $r, $col], 'token' => $decoded['token'], 'index' => count($texts)];
                                $texts[]      = $decoded['text'];
                            }
                        }
                        continue;
                    }

                    if (!Fields::enabled($visit['type'], $field)) continue;
                    if (!is_string($value) || $value === '') continue;
                    $decoded = PwtextCodec::decode($value);
                    if (trim($decoded['text']) === '') continue;

                    $blockSlots[] = ['path' => $visit['path'], 'token' => $decoded['token'], 'index' => count($texts)];
                    $texts[]      = $decoded['text'];
                }
            }
        }

        // ---- Title + meta text fields ----
        /** @var array<string, int> $fieldSlots field => index in $texts */
        $fieldSlots = [];
        foreach (self::TEXT_FIELDS as $field) {
            if (!Fields::enabled('page', $field)) continue;
            $value = $sourceContent[$field] ?? null;
            if (!is_string($value) || trim($value) === '') continue;

            $fieldSlots[$field] = count($texts);
            $texts[]            = $value;
        }

        // ---- Tags fields ----
        /** @var array<string, list<int>> $tagSlots field => indexes in $texts */
        $tagSlots = [];
        foreach (self::TAG_FIELDS as $field) {
            if (!Fields::enabled('page', $field)) continue;
            $value = $sourceContent[$field] ?? null;
            if (!is_string($value)) continue;

            $tags = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($t) => $t !== ''));
            foreach ($tags as $tag) {
                $tagSlots[$field][] = count($texts);
                $texts[]            = $tag;
            }
        }

        if ($this->dryRun || $this->deepl === null) {
            $this->sent = $texts;
            return count($texts);
        }

        $translated = $this->deepl->translate($texts, $target, $source);
        $result     = fn (int $i) => $translated[$i] ?? $texts[$i];

        $update = [];

        if ($blocks !== null) {
            foreach ($blockSlots as $slot) {
                $newValue = PwtextCodec::encode($result($slot['index']), $slot['token']);
                BlockWalker::setByPath($blocks, $slot['path'], $newValue);
            }

            // (structures that came as JSON text go back as such)
            foreach ($reencode as $path) {
                BlockWalker::setByPath($blocks, $path, json_encode(
                    BlockWalker::getByPath($blocks, $path),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
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
}
