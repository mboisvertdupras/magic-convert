<?php

/**
 * Pure sanitizer for the AVIF converter list (config formats.avif.converters).
 *
 * Lives in its own side-effect-free file (no $_POST, no WordPress, no top-level code) so it can be
 * BOTH included by lib/options/submit.php AND require()'d directly into a unit test. submit.php
 * itself cannot be require()'d into tests (it runs the admin save flow at file scope), which is why
 * the testable logic is factored out here — the $_POST/wp_unslash wrapper stays in submit.php.
 *
 * The guard lets both consumers include it without a redeclare error.
 */

if (!function_exists('magicconvert_sanitizeAvifConverters')) {
    /**
     * Sanitize a posted AVIF converter list against the known AVIF converter id space.
     *
     * AVIF converters carry NO per-converter options (no api-keys, no cwebp-style flags), so the
     * shape is intentionally narrow: an ordered list of {converter:<id>[, deactivated:true]}.
     *
     * Rules:
     *   - non-array input            -> [] (caller falls back to existing/default stack).
     *   - item not an array, or with no 'converter' key, or an unknown id  -> dropped.
     *   - duplicate ids              -> collapsed to first occurrence (order preserved).
     *   - kept keys are ONLY {converter, deactivated}; 'deactivated' is kept ONLY when strictly
     *     true and OMITTED otherwise (keeps the saved/default shape diff-free). Every other key
     *     (options, working, error, id, ...) is stripped.
     *
     * @param  mixed     $posted  Decoded posted value (expected: list of {converter,deactivated}).
     * @param  string[]  $known   Allowed AVIF converter ids (AvifStack::defaultConverterIds()).
     * @return array              Clean ordered list of {converter[,deactivated:true]}.
     */
    function magicconvert_sanitizeAvifConverters($posted, array $known)
    {
        if (!is_array($posted)) {
            return [];
        }

        $out = [];
        $seen = [];

        foreach ($posted as $item) {
            if (!is_array($item) || !isset($item['converter'])) {
                continue;
            }
            $id = $item['converter'];
            if (!in_array($id, $known, true)) {
                continue;
            }
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $entry = ['converter' => $id];
            if (isset($item['deactivated']) && ($item['deactivated'] === true)) {
                $entry['deactivated'] = true;
            }
            $out[] = $entry;
        }

        return $out;
    }
}
