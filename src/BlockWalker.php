<?php

namespace Kirbydesk\Translatewizard;

use Closure;

/**
 * Walks the recursive Blocks-structure that pagewizard produces and
 * yields every field that might carry translatable content. The Blocks
 * field on a page holds an array of block dicts:
 *
 *   [ {type, id, content: {tagline, heading, editor, blocks, buttons, …}}, …]
 *
 * Where content fields whose value is a JSON array of blocks are
 * themselves nested Blocks (steplist items, cardlets items, hero
 * buttons, …). This walker descends into those too.
 *
 * The walker is content-only: it does not need the blueprint. It
 * decides what to translate by inspecting field VALUES — a JSON string
 * that decodes to a pwtext/pweditor envelope, or a nested blocks list.
 * Anything else falls through to the "visit plain field" hook, which
 * the caller decides how to handle (translate as text, skip, …).
 */
final class BlockWalker
{
    /**
     * Walk $blocks in place. For every field encountered, $visit is
     * called with:
     *   (fieldName, currentValue, replace)  where replace(newValue)
     * writes the new value back onto the block. $visit returns void.
     *
     * $blocks is passed by reference so the caller receives the mutated
     * structure.
     *
     * @param array<int, array<string, mixed>> $blocks
     * @param Closure(string $fieldName, mixed $value, Closure $replace): void $visit
     */
    public static function walk(array &$blocks, Closure $visit): void
    {
        foreach ($blocks as $blockIndex => &$block) {
            if (!is_array($block) || !isset($block['content']) || !is_array($block['content'])) {
                continue;
            }

            foreach ($block['content'] as $fieldName => $value) {
                // Nested blocks (JSON-encoded array) — descend
                if (self::looksLikeBlocksJson($value)) {
                    $inner = json_decode($value, true);
                    if (is_array($inner)) {
                        self::walk($inner, $visit);
                        $block['content'][$fieldName] = json_encode(
                            $inner,
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                    }
                    continue;
                }

                // Plain field — hand to caller with a replace closure
                $replace = function ($newValue) use (&$block, $fieldName): void {
                    $block['content'][$fieldName] = $newValue;
                };
                $visit($fieldName, $value, $replace);
            }
        }
    }

    private static function looksLikeBlocksJson(mixed $value): bool
    {
        if (!is_string($value)) return false;
        $trim = ltrim($value);
        if ($trim === '' || $trim[0] !== '[') return false;
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || $decoded === []) return false;
        // must look like an array of blocks: each entry is an object with type+content
        foreach ($decoded as $entry) {
            if (!is_array($entry)) return false;
            if (!isset($entry['type']) || !array_key_exists('content', $entry)) return false;
        }
        return true;
    }
}
