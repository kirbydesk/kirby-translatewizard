<?php

namespace Kirbydesk\Translatewizard;

/**
 * Two-phase walker for pagewizard's recursive Blocks structure.
 *
 *   1. decodeAll($blocks)  — parse every JSON-string blocks slot into
 *      a nested PHP array, so the whole tree is uniformly array-based.
 *   2. collect($blocks)    — walk the tree, return a list of visits
 *      {path, value} where path locates each plain field by keys/indexes.
 *   3. caller replaces values via ::setByPath($blocks, $path, $new).
 *   4. encodeAll($blocks)  — re-serialize the nested slots back to JSON.
 *
 * A path is a list of keys/indexes, e.g. [3, 'content', 'blocks', 1,
 * 'content', 'heading']. This avoids the PHP-foreach reference and
 * closure-capture traps that made the mutation-in-place walker unsafe.
 */
final class BlockWalker
{
    /**
     * In-place decode every JSON-encoded nested blocks slot inside
     * $blocks. After this call the tree is uniformly array-based —
     * no JSON strings on nested content['blocks']/content['buttons']/etc.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    public static function decodeAll(array &$blocks): void
    {
        foreach (array_keys($blocks) as $i) {
            if (!is_array($blocks[$i]) || !is_array($blocks[$i]['content'] ?? null)) continue;
            foreach (array_keys($blocks[$i]['content']) as $k) {
                $v = $blocks[$i]['content'][$k];
                if (self::looksLikeBlocksJson($v)) {
                    $inner = json_decode($v, true);
                    if (is_array($inner)) {
                        self::decodeAll($inner);
                        $blocks[$i]['content'][$k] = $inner;
                    }
                }
            }
        }
    }

    /**
     * Walk an already-decoded tree and return every plain field as
     * {path, value}. Nested blocks slots (now arrays, not strings)
     * are descended into.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @return list<array{path: list<int|string>, value: mixed}>
     */
    public static function collect(array $blocks, array $prefix = []): array
    {
        $visits = [];
        foreach ($blocks as $i => $block) {
            if (!is_array($block) || !is_array($block['content'] ?? null)) continue;
            foreach ($block['content'] as $k => $v) {
                $path = [...$prefix, $i, 'content', $k];
                if (is_array($v)) {
                    // nested blocks slot (already decoded to array)
                    if (self::isBlockList($v)) {
                        $visits = [...$visits, ...self::collect($v, $path)];
                    }
                    continue;
                }
                $visits[] = ['path' => $path, 'value' => $v];
            }
        }
        return $visits;
    }

    /**
     * Set a value at $path inside a decoded tree.
     *
     * @param array<int, mixed> $tree
     * @param list<int|string> $path
     */
    public static function setByPath(array &$tree, array $path, mixed $value): void
    {
        $ref = &$tree;
        foreach ($path as $key) {
            if (!isset($ref[$key]) && $ref[$key] !== null) {
                // path invalid — silently ignore
                return;
            }
            $ref = &$ref[$key];
        }
        $ref = $value;
    }

    /**
     * Re-encode every nested-blocks slot (array → JSON string) so the
     * whole tree matches Kirby's on-disk shape again.
     *
     * @param array<int, array<string, mixed>> $blocks
     */
    public static function encodeAll(array &$blocks): void
    {
        foreach (array_keys($blocks) as $i) {
            if (!is_array($blocks[$i]) || !is_array($blocks[$i]['content'] ?? null)) continue;
            foreach (array_keys($blocks[$i]['content']) as $k) {
                $v = $blocks[$i]['content'][$k];
                if (is_array($v) && self::isBlockList($v)) {
                    self::encodeAll($v);
                    $blocks[$i]['content'][$k] = json_encode(
                        $v,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    );
                }
            }
        }
    }

    /**
     * Does $value look like a JSON-encoded array of blocks?
     */
    private static function looksLikeBlocksJson(mixed $value): bool
    {
        if (!is_string($value)) return false;
        $trim = ltrim($value);
        if ($trim === '' || $trim[0] !== '[') return false;
        $decoded = json_decode($value, true);
        return is_array($decoded) && self::isBlockList($decoded);
    }

    /**
     * Does $array look like a decoded list of blocks?
     */
    private static function isBlockList(array $array): bool
    {
        if ($array === []) return false;
        foreach ($array as $entry) {
            if (!is_array($entry)) return false;
            if (!isset($entry['type']) || !array_key_exists('content', $entry)) return false;
        }
        return true;
    }
}
