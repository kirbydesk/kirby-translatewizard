<?php

namespace Kirbydesk\Translatewizard;

use Kirby\Cms\App;
use Kirby\Cms\Blueprint;
use Kirby\Toolkit\I18n;
use Throwable;

/**
 * Which fields are translated. The candidates come from the blueprints:
 * every field of a text type (text, textarea, writer, markdown, list and
 * pagewizard's pwtext / pweditor), in the blocks and on the page (title,
 * meta fields); a structure field counts with its text columns. Each can
 * be switched off in the Project Wizard (Settings › Translation); the
 * choice lives in the project's content/.projectwizard/translate.json,
 * as the differences from the start values:
 *
 *   {"fields": {"pwButton.arialabel": false, "page.metakeywords": false}}
 *
 * Start value: on – except fields holding ids (fragment, aria-describedby).
 */
final class Fields
{
    /** Field types whose value is text. */
    public const TEXT_TYPES = ['text', 'textarea', 'writer', 'markdown', 'list', 'pwtext', 'pweditor', 'tags'];

    /** Text fields holding ids, never words: off unless switched on. */
    private const OFF = ['fragment', 'ariadescribedby'];

    /** Kirby's own blocks: not part of pagewizard's pages. */
    private const CORE_BLOCKS = ['code', 'gallery', 'heading', 'image', 'line', 'list', 'markdown', 'quote', 'table', 'text', 'video'];

    /** The page's own fields (Translator::TEXT_FIELDS / TAG_FIELDS). */
    public const PAGE_FIELDS = [
        'title'               => 'text',
        'metapagetitle'       => 'text',
        'metanavigationtitle' => 'text',
        'metateaser'          => 'textarea',
        'metadescription'     => 'textarea',
        'metakeywords'        => 'tags',
    ];

    /** @var array<string, array<string, array>>|null block type => field => props */
    private static ?array $blockFields = null;

    private static ?array $stored = null;

    /**
     * Is $field of the block type (or "page") translated?
     */
    public static function enabled(string $owner, string $field): bool
    {
        $key = $owner . '.' . $field;
        $stored = self::stored();
        if (array_key_exists($key, $stored)) return (bool) $stored[$key];
        return !in_array($field, self::OFF, true);
    }

    /**
     * The text fields of a block type (lowercase names, as in the content):
     * name => [type, label, columns (structure: its text columns)].
     * Null for a type without blueprint.
     *
     * @return array<string, array{type: string, label: string, columns?: array}>|null
     */
    public static function ofBlock(string $type): ?array
    {
        $all = self::blockFields();
        if (!isset($all[$type])) return null;
        $out = [];
        foreach ($all[$type]['fields'] as $name => $props) {
            $fieldType = $props['type'] ?? '';
            if (in_array($fieldType, self::TEXT_TYPES, true)) {
                $out[$name] = ['type' => $fieldType, 'label' => self::label($props['label'] ?? null, $name)];
            } elseif ($fieldType === 'structure') {
                $columns = [];
                foreach (self::fieldsOf($props) as $col => $colProps) {
                    if (in_array($colProps['type'] ?? '', self::TEXT_TYPES, true)) {
                        $columns[$col] = ['type' => $colProps['type'], 'label' => self::label($colProps['label'] ?? null, $col)];
                    }
                }
                if ($columns) {
                    $out[$name] = ['type' => 'structure', 'label' => self::label($props['label'] ?? null, $name), 'columns' => $columns];
                }
            }
        }
        return $out;
    }

    /**
     * The tree for the Project Wizard: the page, then the blocks (their
     * nested blocks as children – a nested block used by several blocks,
     * such as the button, as an entry of its own).
     */
    public static function tree(): array
    {
        $all = self::blockFields();
        $roots = array_keys(class_exists('pwConfig') ? \pwConfig::registered() : []);
        $roots = array_values(array_filter($roots, fn ($t) => isset($all[$t])));

        // nested block types and their parents
        $parents = [];
        $walk = function (string $type, array $seen) use (&$walk, &$parents, $all) {
            foreach ($all[$type]['nested'] ?? [] as $child) {
                if (!isset($all[$child]) || in_array($child, $seen, true)) continue;
                $parents[$child][$type] = true;
                $walk($child, [...$seen, $child]);
            }
        };
        foreach ($roots as $root) $walk($root, [$root]);
        $shared = array_keys(array_filter($parents, fn ($p) => count($p) > 1));

        $node = function (string $type) use (&$node, $all, $shared): ?array {
            $children = [];
            foreach ($all[$type]['nested'] ?? [] as $child) {
                if (in_array($child, $shared, true) || !isset($all[$child]) || $child === $type) continue;
                $c = $node($child);
                if ($c) $children[] = $c;
            }
            $fields = self::nodeFields($type, self::ofBlock($type) ?? []);
            if (!$fields && !$children) return null;
            return [
                'key'      => $type,
                'label'    => $all[$type]['label'],
                'icon'     => $all[$type]['icon'],
                'fields'   => $fields,
                'children' => $children,
            ];
        };

        $pageFields = [];
        foreach (self::PAGE_FIELDS as $name => $fieldType) {
            $pageFields[$name] = ['type' => $fieldType, 'label' => self::pageLabel($name)];
        }
        $tree = [[
            'key'      => 'page',
            'label'    => I18n::translate('translatewizard.fields.page', 'Page'),
            'icon'     => 'page',
            'fields'   => self::nodeFields('page', $pageFields),
            'children' => [],
        ]];
        foreach ([...$roots, ...$shared] as $type) {
            $n = $node($type);
            if ($n) $tree[] = $n;
        }
        return $tree;
    }

    /**
     * Store the choice: only the differences from the start values.
     *
     * @param array<string, bool> $values "owner.field" => on/off
     */
    public static function save(array $values): void
    {
        $diff = [];
        foreach ($values as $key => $on) {
            if (!is_string($key) || !str_contains($key, '.')) continue;
            $field = substr($key, strrpos($key, '.') + 1);
            $start = !in_array($field, self::OFF, true);
            if ((bool) $on !== $start) $diff[$key] = (bool) $on;
        }
        ksort($diff);
        $path = self::file();
        if ($diff === []) {
            if (is_file($path)) unlink($path);
        } else {
            if (!is_dir(dirname($path))) mkdir(dirname($path), 0755, true);
            file_put_contents($path, json_encode(['fields' => $diff], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        self::$stored = null;
    }

    // ------------------------------------------------------------------

    /** A node's fields for the tree: key, label, type, on/off. */
    private static function nodeFields(string $owner, array $fields): array
    {
        $out = [];
        foreach ($fields as $name => $props) {
            if (($props['type'] ?? '') === 'structure') {
                foreach ($props['columns'] as $col => $colProps) {
                    $out[] = [
                        'key'   => $owner . '.' . $name . '.' . $col,
                        'name'  => $name . ' › ' . $col,
                        'label' => $props['label'] . ' › ' . $colProps['label'],
                        'type'  => $colProps['type'],
                        'value' => self::enabled($owner, $name . '.' . $col),
                    ];
                }
                continue;
            }
            $out[] = [
                'key'   => $owner . '.' . $name,
                'name'  => $name,
                'label' => $props['label'],
                'type'  => $props['type'],
                'value' => self::enabled($owner, $name),
            ];
        }
        return $out;
    }

    /** @return array<string, array{label: string, icon: string, fields: array, nested: list<string>}> */
    private static function blockFields(): array
    {
        if (self::$blockFields !== null) return self::$blockFields;
        $out = [];
        foreach (App::instance()->blueprints('blocks') as $type) {
            if (in_array($type, self::CORE_BLOCKS, true)) continue;
            try {
                $bp = Blueprint::find('blocks/' . $type);
            } catch (Throwable) {
                continue;
            }
            if (!is_array($bp)) continue;
            $fields = self::fieldsOf($bp);
            $nested = [];
            foreach ($fields as $props) {
                if (($props['type'] ?? '') !== 'blocks') continue;
                foreach ((array) ($props['fieldsets'] ?? []) as $k => $v) {
                    $nested[] = is_string($v) ? $v : (string) $k;
                }
            }
            $out[$type] = [
                'label'  => self::label($bp['name'] ?? null, $type),
                'icon'   => is_string($bp['icon'] ?? null) ? $bp['icon'] : 'box',
                'fields' => $fields,
                'nested' => array_values(array_unique($nested)),
            ];
        }
        return self::$blockFields = $out;
    }

    /**
     * The fields of a blueprint part, through its tabs, columns and
     * sections, each with its extends resolved (lowercase names).
     *
     * @return array<string, array>
     */
    private static function fieldsOf(array $node): array
    {
        $out = [];
        foreach (['tabs', 'columns', 'sections'] as $group) {
            foreach ((array) ($node[$group] ?? []) as $part) {
                if (is_string($part)) $part = Blueprint::extend($part);
                if (is_array($part)) $out = [...$out, ...self::fieldsOf(Blueprint::extend($part))];
            }
        }
        foreach ((array) ($node['fields'] ?? []) as $name => $props) {
            if (is_string($props)) $props = Blueprint::extend($props);
            if (!is_array($props)) continue;
            $out[strtolower((string) $name)] = Blueprint::extend($props);
        }
        return $out;
    }

    private static function label(mixed $label, string $fallback): string
    {
        if (is_array($label)) return (string) (I18n::translate($label) ?? $fallback);
        if (is_string($label) && $label !== '') return (string) I18n::translate($label, $label);
        return $fallback;
    }

    private static function pageLabel(string $name): string
    {
        return (string) I18n::translate('translatewizard.fields.' . $name, $name);
    }

    private static function stored(): array
    {
        if (self::$stored !== null) return self::$stored;
        $data = is_file(self::file()) ? json_decode((string) file_get_contents(self::file()), true) : null;
        return self::$stored = is_array($data['fields'] ?? null) ? $data['fields'] : [];
    }

    private static function file(): string
    {
        $dir = class_exists('pwConfig')
            ? \pwConfig::projectDir()
            : App::instance()->root('content') . '/.projectwizard';
        return $dir . '/translate.json';
    }
}
