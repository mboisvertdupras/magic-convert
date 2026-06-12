<?php

namespace MagicConvert;

/**
 * NginxPanel — the pure, testable glue between NginxRules (the generator) and the nginx admin
 * experience (Phase 3.2): the options-page panel, the secure download endpoint, and the
 * "rules need updating" notice.
 *
 * This class deliberately holds NO rendering and NO WordPress calls. It is the single source of
 * truth for:
 *   - the artifact-key WHITELIST (a | b | maps | server) and its normalization, so neither the
 *     download endpoint nor the panel ever dispatches on free-form input, and
 *   - the fingerprint-CHANGE decision that drives the update notice.
 *
 * Both pieces are unit-tested without bootstrapping WordPress.
 *
 * SECURITY: artifacts are generated on the fly (NginxRules has no disk-writing method); they
 * embed the 32-hex config hash and must never be written to a web-accessible path. The download
 * endpoint (NginxDownload) is the only egress, and it is capability-gated + nonce-verified.
 */
class NginxPanel
{
    /**
     * The whitelisted artifact keys, canonical form => human label. NOTHING outside this map is
     * ever generated or streamed. 'maps'/'server' are the two files of Artifact A; 'single' is
     * Artifact B. The request-facing aliases 'a' and 'b' are resolved to these by
     * resolveArtifactKey().
     *
     * @var array<string,string>
     */
    const ARTIFACTS = [
        'maps'   => 'Standard — http-context maps file',
        'server' => 'Standard — server-context include file',
        'single' => 'Single file (control panels)',
    ];

    /**
     * Request-facing aliases accepted by the download endpoint, mapped to canonical artifact keys.
     * Lets the UI ask for 'a' (the preferred two-file set is requested file-by-file as 'maps' /
     * 'server') or 'b' (the single file). We keep the canonical keys AND the convenience aliases
     * so the documented endpoint contract ('a|b|maps|server'/'single') is honoured exactly, while
     * dispatch stays on a closed set.
     *
     * @var array<string,string>
     */
    const ALIASES = [
        'a'      => 'server',  // 'a' alone is ambiguous (two files) — default to the server file
        'b'      => 'single',
        'maps'   => 'maps',
        'server' => 'server',
        'single' => 'single',
    ];

    /**
     * The download filename for each canonical artifact key. Stable, non-secret, .conf so it
     * drops straight into an nginx include directory.
     *
     * @var array<string,string>
     */
    const FILENAMES = [
        'maps'   => 'magic-convert-maps.conf',
        'server' => 'magic-convert-server.conf',
        'single' => 'magic-convert.conf',
    ];

    /**
     * Normalize an incoming (possibly hostile) artifact key to a canonical key, or return false
     * if it is not in the whitelist. This is the ONLY gate between request input and artifact
     * dispatch — callers MUST treat a false return as "reject".
     *
     * @param  mixed  $key
     * @return string|false  canonical key ('maps'|'server'|'single') or false.
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
     * Generate the artifact body for a (resolved) canonical key from the live Paths environment.
     * Generates on the fly — never reads from disk. Throws on an unknown key so a programming
     * error can't silently stream the wrong file.
     *
     * @param  string  $canonicalKey  one of 'maps'|'server'|'single' (already resolved).
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
     * The download filename for a canonical artifact key.
     *
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
     * Decide whether the persisted nginx-rules state shows a fingerprint CHANGE relative to a
     * freshly computed record — i.e. the installed rules are now stale and the user must
     * re-download. Pure: takes the two state records (each a { fingerprint, ... } triple from
     * NginxRules::stateRecordFromPaths) and returns a bool.
     *
     * Semantics:
     *   - No previous record (first save) => NOT a change (nothing was ever installed to drift
     *     from; the panel itself is the call-to-action on a fresh nginx install).
     *   - Previous record present but missing/empty fingerprint => treat as changed (defensive:
     *     a corrupt record should surface the notice rather than hide it).
     *   - Otherwise: changed iff the fingerprints differ.
     *
     * @param  array|null  $oldRecord  previously persisted state (State::getState('nginx-rules')).
     * @param  array       $newRecord  freshly computed record for the config being saved.
     * @return bool
     */
    public static function fingerprintChanged($oldRecord, $newRecord)
    {
        $new = is_array($newRecord) && isset($newRecord['fingerprint'])
            ? (string) $newRecord['fingerprint']
            : '';

        // First save / no prior state => not a "change" (there is nothing installed to go stale).
        if (!is_array($oldRecord) || !isset($oldRecord['fingerprint'])) {
            return false;
        }

        $old = (string) $oldRecord['fingerprint'];

        // Defensive: an empty/corrupt previous fingerprint should err toward showing the notice.
        if ($old === '') {
            return true;
        }

        return $old !== $new;
    }

    /**
     * Short, display-friendly form of a fingerprint (first 12 hex). Used in the panel's
     * "Rules version: <short> (generated <date>)" line.
     *
     * @param  string  $fingerprint
     * @return string
     */
    public static function shortFingerprint($fingerprint)
    {
        $fingerprint = (string) $fingerprint;
        return ($fingerprint === '') ? '(none)' : substr($fingerprint, 0, 12);
    }
}
