<?php

namespace Kirbydesk\Translatewizard;

use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;
use Kirby\Uuid\Uuid;
use Throwable;

/**
 * Translates a page between two languages. Reads the source-language
 * content, collects the translatable texts – the text fields of the
 * page's template (title, meta fields …), its blocks fields, walked
 * recursively into nested blocks / buttons, and its media's texts – hands them to DeepL in one
 * batch, and writes the result back to the target language. Pages also
 * get a slug derived from the translated title. Which fields count: their
 * type in the blueprints and the choice in the Project Wizard (Fields).
 */
final class Translator
{
    /** A dry run: the texts that would go to DeepL (nothing is sent or written). */
    public array $sent = [];

    /** @var list<string> */
    private array $texts = [];

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
        $content  = $page->content($source)->toArray();
        $template = $page instanceof Page ? $page->intendedTemplate()->name() : null;
        $owner    = 'page:' . ($template ?? 'site');
        $this->texts = [];

        // ---- the template's text fields (title, meta fields …) ----
        $own = ($template !== null ? Fields::ofPage($template) : null) ?? ['title' => ['type' => 'text']];
        /** @var array<string, array> $fieldSlots field => slot(s) */
        $fieldSlots = [];
        /** @var array<string, array> $rowsOf a structure field's decoded rows */
        $rowsOf = [];
        foreach ($own as $field => $props) {
            $value = $content[$field] ?? null;
            if ($props['type'] === 'structure') {
                $rows = is_string($value) ? Data::decode($value, 'yaml') : $value;
                if (!is_array($rows) || !array_is_list($rows)) continue;
                $slots = [];
                foreach ($rows as $r => $row) {
                    foreach (array_keys($props['columns']) as $col) {
                        if (!Fields::enabled($owner, $field . '.' . $col)) continue;
                        $slot = $this->add($row[$col] ?? null);
                        if ($slot) $slots[] = [...$slot, 'path' => [$r, $col]];
                    }
                }
                if ($slots) {
                    $rowsOf[$field] = $rows;
                    $fieldSlots[$field] = ['kind' => 'structure', 'slots' => $slots];
                }
                continue;
            }
            if (!Fields::enabled($owner, $field) || !is_string($value)) continue;
            // tags: tag by tag
            if ($props['type'] === 'tags') {
                $tags = array_values(array_filter(array_map('trim', explode(',', $value)), fn ($t) => $t !== ''));
                $slots = array_values(array_filter(array_map(fn ($t) => $this->add($t), $tags)));
                if ($slots) $fieldSlots[$field] = ['kind' => 'tags', 'slots' => $slots];
                continue;
            }
            $slot = $this->add($value);
            if ($slot) $fieldSlots[$field] = ['kind' => 'text', 'slots' => [$slot]];
        }

        // ---- the blocks fields ----
        /** @var array<string, array{blocks: array, slots: list<array>, reencode: list<array>}> $trees */
        $trees = [];
        foreach ($template !== null ? Fields::blocksOfPage($template) : ['blocks'] as $field) {
            $raw = $content[$field] ?? null;
            if (!is_string($raw)) continue;
            $blocks = Data::decode($raw, 'json');
            if (!is_array($blocks) || $blocks === []) continue;
            // (uniform array-tree – nested blocks become sub-arrays, not JSON strings)
            BlockWalker::decodeAll($blocks);
            $trees[$field] = ['blocks' => $blocks, 'slots' => [], 'reencode' => []];
            $this->collectBlocks($trees[$field]);
        }

        // ---- the media: the page's files, and the files it uses from
        // elsewhere that have no translation yet (Fields: file templates) ----
        $fileSlots = [];
        foreach ($this->files($page, $content, $target) as $file) {
            $fields = Fields::ofFile($file->template() ?? '');
            if (!$fields) continue;
            $fileContent = $file->content($source)->toArray();
            $slots = [];
            foreach ($fields as $field => $props) {
                if ($props['type'] === 'structure' || !Fields::enabled('file:' . $file->template(), $field)) continue;
                $value = $fileContent[$field] ?? null;
                if ($props['type'] === 'tags' && is_string($value)) {
                    $tags = array_values(array_filter(array_map(fn ($t) => $this->add(trim($t)), explode(',', $value))));
                    if ($tags) $slots[$field] = ['kind' => 'tags', 'slots' => $tags];
                    continue;
                }
                $slot = $this->add($value);
                if ($slot) $slots[$field] = ['kind' => 'text', 'slots' => [$slot]];
            }
            if ($slots) $fileSlots[] = ['file' => $file, 'slots' => $slots];
        }

        if ($this->dryRun || $this->deepl === null) {
            $this->sent = $this->texts;
            return count($this->texts);
        }

        $translated = $this->texts === [] ? [] : $this->deepl->translate($this->texts, $target, $source);
        $result = fn (array $slot) => PwtextCodec::encode($translated[$slot['index']] ?? $this->texts[$slot['index']], $slot['token']);

        $update = [];
        foreach ($fieldSlots as $field => $entry) {
            if ($entry['kind'] === 'text') {
                $update[$field] = $result($entry['slots'][0]);
            } elseif ($entry['kind'] === 'tags') {
                $update[$field] = implode(', ', array_map($result, $entry['slots']));
            } else {
                $rows = $rowsOf[$field];
                foreach ($entry['slots'] as $slot) {
                    $rows[$slot['path'][0]][$slot['path'][1]] = $result($slot);
                }
                $update[$field] = Data::encode($rows, 'yaml');
            }
        }

        foreach ($trees as $field => $tree) {
            $blocks = $tree['blocks'];
            foreach ($tree['slots'] as $slot) {
                BlockWalker::setByPath($blocks, $slot['path'], $result($slot));
            }
            // (structures that came as JSON text go back as such)
            foreach ($tree['reencode'] as $path) {
                BlockWalker::setByPath($blocks, $path, json_encode(
                    BlockWalker::getByPath($blocks, $path),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
            }
            // written even without translatable units, so the target
            // language mirrors the source language's block structure
            BlockWalker::encodeAll($blocks);
            $update[$field] = json_encode($blocks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($update !== []) {
            $page = $page->update($update, $target);
        }

        foreach ($fileSlots as $entry) {
            $fileUpdate = [];
            foreach ($entry['slots'] as $field => $slotEntry) {
                $fileUpdate[$field] = $slotEntry['kind'] === 'tags'
                    ? implode(', ', array_map($result, $slotEntry['slots']))
                    : $result($slotEntry['slots'][0]);
            }
            $entry['file']->update($fileUpdate, $target);
        }

        if (isset($update['title'])) {
            $this->translateSlug($page, $update['title'], $target);
        }

        return count($this->texts);
    }

    /**
     * The files to translate with the page: its own (always, as its
     * content), and those it uses from elsewhere (file:// in its content)
     * while they have no translation in the target language yet – a file
     * shared by many pages is not translated again with each of them.
     *
     * @return list<\Kirby\Cms\File>
     */
    private function files(Page|Site $page, array $content, string $target): array
    {
        $files = [];
        foreach ($page->files() as $file) $files[$file->id()] = $file;

        $raw = implode("\n", array_filter($content, 'is_string'));
        preg_match_all('~file://[A-Za-z0-9]+~', $raw, $matches);
        foreach (array_unique($matches[0]) as $uuid) {
            try {
                $file = Uuid::for($uuid)?->model();
            } catch (Throwable) {
                continue;
            }
            if (!$file instanceof \Kirby\Cms\File || isset($files[$file->id()])) continue;
            if ($file->version('latest')->exists($target)) continue;
            $files[$file->id()] = $file;
        }
        return array_values($files);
    }

    /**
     * A text for DeepL: its slot (index, codec token), or null when there
     * is nothing to translate.
     */
    private function add(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') return null;
        $decoded = PwtextCodec::decode($value);
        if (trim($decoded['text']) === '') return null;
        $this->texts[] = $decoded['text'];
        return ['index' => count($this->texts) - 1, 'token' => $decoded['token']];
    }

    /**
     * The texts of a blocks field: per block its text fields by the
     * block's blueprint and the choice (Fields), a structure column by
     * column; a block without blueprint only with pagewizard's own text
     * fields (their JSON envelope says so).
     */
    private function collectBlocks(array &$tree): void
    {
        foreach (BlockWalker::collect($tree['blocks']) as $visit) {
            $fields = Fields::ofBlock($visit['type']);
            $field  = $visit['field'];
            $value  = $visit['value'];

            if ($fields === null) {
                if (!is_string($value) || PwtextCodec::decode($value)['token'] === null) continue;
                $slot = $this->add($value);
                if ($slot) $tree['slots'][] = [...$slot, 'path' => $visit['path']];
                continue;
            }
            if (!isset($fields[$field])) continue;

            if ($fields[$field]['type'] === 'structure') {
                $rows = is_string($value) ? json_decode($value, true) : $value;
                if (!is_array($rows) || !array_is_list($rows)) continue;
                if (is_string($value)) {
                    BlockWalker::setByPath($tree['blocks'], $visit['path'], $rows);
                    $tree['reencode'][] = $visit['path'];
                }
                foreach ($rows as $r => $row) {
                    if (!is_array($row)) continue;
                    foreach (array_keys($fields[$field]['columns']) as $col) {
                        if (!Fields::enabled($visit['type'], $field . '.' . $col)) continue;
                        $slot = $this->add($row[$col] ?? null);
                        if ($slot) $tree['slots'][] = [...$slot, 'path' => [...$visit['path'], $r, $col]];
                    }
                }
                continue;
            }

            if (!Fields::enabled($visit['type'], $field)) continue;
            $slot = $this->add($value);
            if ($slot) $tree['slots'][] = [...$slot, 'path' => $visit['path']];
        }
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
