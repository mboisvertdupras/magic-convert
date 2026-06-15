<?php

namespace MagicConvert;

class NginxPanel
{
    /**
     * @var array<string,string>
     */
    const ARTIFACTS = [
        'maps'   => 'Standard — http-context maps file',
        'server' => 'Standard — server-context include file',
        'single' => 'Single file (control panels)',
    ];

    /**
     * @var array<string,string>
     */
    const ALIASES = [
        'a'      => 'server',
        'b'      => 'single',
        'maps'   => 'maps',
        'server' => 'server',
        'single' => 'single',
    ];

    /**
     * @var array<string,string>
     */
    const FILENAMES = [
        'maps'   => 'magic-convert-maps.conf',
        'server' => 'magic-convert-server.conf',
        'single' => 'magic-convert.conf',
    ];

    /**
     * @param  mixed  $key
     * @return string|false
     */
    public static function resolveArtifactKey($key)
    {
        if (!is_string($key)) {
            return false;
        }
        $key = strtolower(trim($key));
        if (isset(self::ALIASES[$key])) {
            return self::ALIASES[$key];
        }
        return false;
    }

    /**
     * @param  string  $canonicalKey
     * @param  array   $config
     * @return string
     * @throws \InvalidArgumentException
     */
    public static function generateArtifactFromPaths($canonicalKey, $config)
    {
        switch ($canonicalKey) {
            case 'maps':
                return NginxRules::generateMapsFileFromPaths($config);
            case 'server':
                return NginxRules::generateServerFileFromPaths($config);
            case 'single':
                return NginxRules::generateSingleFileFromPaths($config);
            default:
                throw new \InvalidArgumentException('Unknown nginx artifact key: ' . $canonicalKey);
        }
    }

    /**
     * @param  string  $canonicalKey
     * @return string
     */
    public static function downloadFilename($canonicalKey)
    {
        return isset(self::FILENAMES[$canonicalKey])
            ? self::FILENAMES[$canonicalKey]
            : 'magic-convert.conf';
    }

    /**
     * @param  array|null  $oldRecord
     * @param  array       $newRecord
     * @return bool
     */
    public static function fingerprintChanged($oldRecord, $newRecord)
    {
        $new = is_array($newRecord) && isset($newRecord['fingerprint'])
            ? (string) $newRecord['fingerprint']
            : '';

        if (!is_array($oldRecord) || !isset($oldRecord['fingerprint'])) {
            return false;
        }

        $old = (string) $oldRecord['fingerprint'];

        if ($old === '') {
            return true;
        }

        return $old !== $new;
    }

    /**
     * @param  string  $fingerprint
     * @return string
     */
    public static function shortFingerprint($fingerprint)
    {
        $fingerprint = (string) $fingerprint;
        return ($fingerprint === '') ? '(none)' : substr($fingerprint, 0, 12);
    }
}
