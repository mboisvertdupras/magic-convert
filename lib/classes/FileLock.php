<?php

/*
 * This class is made to NOT depend on WordPress functions and must be kept like that.
 * It is used by ConvertHelperIndependent (which runs both inside WordPress for bulk/AJAX
 * conversion AND inside the wod scripts, which do not bootstrap WordPress).
 */
namespace MagicConvert;

/**
 * Cross-process advisory file lock.
 *
 * ## Why not flock()?
 *
 * The conversion core must be safe against *multiple, independent OS processes*
 * writing the same destination file concurrently:
 *
 *   - parallel admin-ajax / REST requests served by separate PHP-FPM workers, and
 *   - concurrent WP-CLI processes (the planned `--procs=<n>` shard fan-out).
 *
 * `flock()` is unreliable across that boundary: it is tied to the open file
 * description / process and behaves inconsistently on NFS and across FPM
 * workers. We instead use the classic, portable primitive that works the same
 * everywhere a POSIX-ish filesystem does: **atomic exclusive file creation**.
 *
 * `fopen($lockPath, 'x')` maps to `open(O_CREAT | O_EXCL)`, which the kernel
 * guarantees to be atomic: exactly one caller can create the file; everyone
 * else gets `false`. That is our mutex. The losing callers see the lock as
 * "held" and back off ("conversion already in progress").
 *
 * ## Stale locks
 *
 * A process can die (OOM kill, fatal error, timeout) while holding the lock,
 * leaving the lock file behind forever. To avoid a permanently wedged
 * destination, a lock whose file mtime is older than `$staleSeconds` (default
 * 10 minutes — comfortably longer than even a slow 4K AVIF encode) is
 * considered abandoned: we steal it (unlink + retry the exclusive create once).
 * The retry is bounded to a single attempt so two racers stealing the same
 * stale lock cannot loop; at most one wins the re-create, the other backs off.
 *
 * ## Lock file contents & ownership tokens
 *
 * On successful acquisition we write a small JSON document containing the pid,
 * a unix timestamp, and a per-acquisition **owner token** (a random,
 * collision-resistant string). The pid/timestamp are diagnostic (so an operator
 * inspecting a `.lock` file can see who holds it and since when); the token is
 * load-bearing for release.
 *
 * `acquire()` returns the token (not just `true`) and `release()` requires it.
 * release() unlinks the lock file *only* when the token written in the file
 * still matches the token the releaser was handed. This closes a correctness
 * gap in the long-running / stale-steal case:
 *
 *   1. Process A acquires the lock (token T_A) and starts a slow encode.
 *   2. A's encode exceeds the stale threshold; process B sees the lock as stale,
 *      steals it (unlink + re-create) and writes a *fresh* token T_B. B now
 *      legitimately owns the destination and is writing it.
 *   3. A finishes and calls release() with T_A. The file now carries T_B, so the
 *      tokens differ and A does NOT unlink B's lock.
 *
 * Without the token, A's release() would delete B's live lock, letting a third
 * process C acquire and write the SAME destination concurrently with B —
 * defeating the mutex during exactly the long-running case stale-stealing exists
 * to handle. The token makes release idempotent and owner-scoped.
 *
 * ## Directory creation race
 *
 * The lock file's parent directory may not exist yet, and two processes may try
 * to create it simultaneously. We use the tolerant EEXIST pattern: `@mkdir(...,
 * true)` (suppressing the warning when a racer wins) followed by an `is_dir()`
 * re-check that is the real success criterion.
 *
 * This class is intentionally WordPress-independent.
 */
class FileLock
{
    /**
     * Default age (seconds) after which a lock file is treated as stale/abandoned
     * and may be stolen. 10 minutes — longer than any realistic single conversion.
     */
    const DEFAULT_STALE_SECONDS = 600;

    /**
     * Attempt to acquire the lock at $lockPath.
     *
     * On success the caller is handed an opaque **owner token** which it MUST
     * pass back to release(). The token identifies *this* acquisition: if the
     * lock is later stolen as stale and re-acquired by someone else, that owner
     * gets a different token, so a late release() by the original owner cannot
     * delete the new owner's live lock.
     *
     * @param  string  $lockPath      Absolute path to the lock file to create.
     * @param  int     $staleSeconds  Age after which an existing lock is stolen.
     *
     * @return string|false  The owner token (non-empty string) if the lock was
     *                       acquired — the caller now owns it and MUST eventually
     *                       call release($lockPath, $token). False if it is held
     *                       by a live process / could not be created.
     */
    public static function acquire($lockPath, $staleSeconds = self::DEFAULT_STALE_SECONDS)
    {
        if (!self::ensureParentDir($lockPath)) {
            return false;
        }

        $token = self::newToken();

        if (self::tryCreate($lockPath, $token)) {
            return $token;
        }

        // Could not create => either it already exists (held) or a real error.
        // If it exists and is stale, steal it and retry exactly once.
        if (self::isStale($lockPath, $staleSeconds)) {
            @unlink($lockPath);
            if (self::tryCreate($lockPath, $token)) {
                return $token;
            }
        }

        return false;
    }

    /**
     * Release a lock previously acquired with acquire().
     *
     * The lock file is removed ONLY when it still carries the token this caller
     * was handed by acquire(). This makes release owner-scoped and idempotent:
     *
     *   - lock file already gone (e.g. stolen as stale) => no-op,
     *   - lock file now owned by someone else (different token, e.g. our lock was
     *     stolen and re-acquired while we ran long) => left untouched,
     *   - lock file still ours (token matches) => unlinked.
     *
     * Never throws. A missing/empty $token degrades to a safe no-op rather than
     * a blind unlink, so callers can never delete a lock they do not own.
     *
     * @param  string  $lockPath  Absolute path to the lock file.
     * @param  string  $token     The token returned by the matching acquire().
     */
    public static function release($lockPath, $token)
    {
        if (!is_string($token) || $token === '') {
            // Without a token we cannot prove ownership; refuse to unlink.
            return;
        }
        if (!@is_file($lockPath)) {
            return;
        }
        if (self::tokenOf($lockPath) !== $token) {
            // The file is no longer ours (stolen + re-acquired, or never ours).
            // Leaving it alone preserves the current owner's mutual exclusion.
            return;
        }
        @unlink($lockPath);
    }

    /**
     * Atomically create the lock file (O_EXCL) and write the owner payload
     * stamped with $token.
     *
     * @return bool  True if WE created it (and thus hold the lock).
     */
    private static function tryCreate($lockPath, $token)
    {
        // 'x' => O_CREAT | O_EXCL | O_WRONLY: fails if the file already exists.
        // The "@" suppresses the warning emitted on the (expected) EEXIST case.
        $handle = @fopen($lockPath, 'x');
        if ($handle === false) {
            return false;
        }

        @fwrite($handle, self::ownerPayload($token));
        @fclose($handle);
        return true;
    }

    /**
     * Generate a per-acquisition owner token: collision-resistant across
     * concurrent processes (pid + high-entropy random + time).
     *
     * @return string
     */
    private static function newToken()
    {
        $pid = function_exists('getmypid') ? (int) getmypid() : 0;

        $rand = '';
        if (function_exists('random_bytes')) {
            try {
                $rand = bin2hex(random_bytes(16));
            } catch (\Exception $e) {
                $rand = '';
            } catch (\Error $e) {
                $rand = '';
            }
        }
        if ($rand === '') {
            // Fallback if a CSPRNG is unavailable. uniqid(more_entropy) plus
            // mt_rand keeps tokens distinct enough for advisory locking.
            $rand = uniqid('', true) . '.' . mt_rand();
        }

        return $pid . '-' . $rand;
    }

    /**
     * Read the owner token stored in an existing lock file.
     *
     * @return string  The token, or '' if the file is unreadable / has no token.
     */
    private static function tokenOf($lockPath)
    {
        $raw = @file_get_contents($lockPath);
        if ($raw === false || $raw === '') {
            return '';
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !isset($payload['token']) || !is_string($payload['token'])) {
            return '';
        }
        return $payload['token'];
    }

    /**
     * The JSON payload written into a freshly created lock file. The 'token' is
     * load-bearing (verified on release); pid/time/created are diagnostic.
     */
    private static function ownerPayload($token)
    {
        $pid = function_exists('getmypid') ? getmypid() : 0;
        return json_encode([
            'token' => $token,
            'pid' => $pid,
            'time' => time(),
            'created' => date('c'),
        ]);
    }

    /**
     * Is an existing lock file older than $staleSeconds (by mtime)?
     *
     * @return bool  True if the file exists and is older than the threshold.
     *               False if it does not exist, mtime is unreadable, or it is fresh.
     */
    private static function isStale($lockPath, $staleSeconds)
    {
        if (!@is_file($lockPath)) {
            return false;
        }
        $mtime = @filemtime($lockPath);
        if ($mtime === false) {
            return false;
        }
        return (time() - $mtime) > $staleSeconds;
    }

    /**
     * Ensure the parent directory of $lockPath exists, tolerating the EEXIST race.
     *
     * @return bool  True if the directory exists (or was created) afterwards.
     */
    private static function ensureParentDir($lockPath)
    {
        $dir = self::dirName($lockPath);
        if ($dir === '' || @is_dir($dir)) {
            return true;
        }
        // @ suppresses the warning when a concurrent process created it first.
        @mkdir($dir, 0775, true);
        return @is_dir($dir);
    }

    /**
     *  Directory part of a path. Works with both forward and back slashes and
     *  does not touch the filesystem (mirrors FileHelper::dirName, kept local so
     *  this class has no extra dependency).
     */
    private static function dirName($path)
    {
        return preg_replace('/[\/\\\\][^\/\\\\]*$/', '', $path);
    }
}
