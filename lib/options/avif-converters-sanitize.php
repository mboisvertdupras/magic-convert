<?php

if (!function_exists('magicconvert_sanitizeAvifConverters')) {
    /**
     * @param  string[]  $known
     * @return array
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
