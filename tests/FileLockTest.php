<?php

namespace MagicConvert\Tests;

use MagicConvert\FileLock;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MagicConvert\FileLock — the cross-process, O_EXCL-based advisory
 * lock that serializes writers of a conversion destination.
 *
 * Covered:
 *  - acquire/release roundtrip
 *  - acquire() returns an opaque owner token (not a bare bool)
 *  - a second acquire fails while the lock is held
 *  - a stale lock (backdated mtime) is stolen
 *  - the lock file's contents carry the pid and the owner token
 *  - release() is owner-scoped: a stale-stolen lock re-acquired by another owner
 *    is NOT deleted by the original owner's late release() (the mutex-defeating
 *    cross-owner unlink the token mechanism exists to prevent)
 *  - release() refuses to unlink without a matching token
 *  - REAL cross-process contention: a child php process holds the lock for ~1s
 *    and the parent must NOT be able to acquire it during that window.
 */
class FileLockTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mc-filelock-' . getmypid() . '-' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        // Remove everything we created.
        if (is_dir($this->tmpDir)) {
            foreach (scandir($this->tmpDir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                @unlink($this->tmpDir . '/' . $f);
            }
            @rmdir($this->tmpDir);
        }
    }

    private function lockPath(): string
    {
        return $this->tmpDir . '/dest.webp.lock';
    }

    public function testAcquireReleaseRoundtrip(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertNotFalse($token, 'first acquire should succeed');
        $this->assertFileExists($lock, 'lock file should exist while held');

        FileLock::release($lock, $token);
        $this->assertFileDoesNotExist($lock, 'lock file should be gone after release');

        // Re-acquire after release must work again.
        $token2 = FileLock::acquire($lock);
        $this->assertNotFalse($token2, 'acquire after release should succeed');
        FileLock::release($lock, $token2);
    }

    public function testAcquireReturnsOpaqueToken(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        // The contract changed from bool to token|false: success must be a
        // non-empty string, never literal true.
        $this->assertIsString($token, 'acquire should return a token string on success');
        $this->assertNotSame('', $token, 'token must be non-empty');
        $this->assertNotSame(true, $token, 'acquire must not return a bare bool true');

        // The token must be the one persisted in the lock file.
        $payload = json_decode(file_get_contents($lock), true);
        $this->assertSame($token, $payload['token'] ?? null, 'returned token must match the stored token');

        FileLock::release($lock, $token);
    }

    public function testEachAcquireGetsDistinctToken(): void
    {
        $lock = $this->lockPath();

        $first = FileLock::acquire($lock);
        $this->assertNotFalse($first);
        FileLock::release($lock, $first);

        $second = FileLock::acquire($lock);
        $this->assertNotFalse($second);
        $this->assertNotSame($first, $second, 'each acquisition must get a fresh, distinct token');
        FileLock::release($lock, $second);
    }

    public function testSecondAcquireFailsWhileHeld(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertNotFalse($token);
        // Held by "us" (same process). A second acquire of a fresh lock must fail.
        $this->assertFalse(FileLock::acquire($lock), 'acquire while held must fail');

        FileLock::release($lock, $token);
    }

    public function testReleaseIsSafeWhenLockMissing(): void
    {
        // Releasing a non-existent lock must be a harmless no-op (never throws).
        FileLock::release($this->lockPath(), 'some-token');
        $this->assertFileDoesNotExist($this->lockPath());
    }

    public function testReleaseWithWrongTokenDoesNotUnlink(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertNotFalse($token);

        // A releaser holding the WRONG token must not delete the live lock.
        FileLock::release($lock, $token . '-not-mine');
        $this->assertFileExists($lock, 'lock must survive a release with a non-matching token');

        // And a second acquire must still be refused (lock genuinely still held).
        $this->assertFalse(FileLock::acquire($lock), 'lock must remain held after a wrong-token release');

        // The real owner can still release.
        FileLock::release($lock, $token);
        $this->assertFileDoesNotExist($lock);
    }

    public function testReleaseWithEmptyTokenDoesNotUnlink(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertNotFalse($token);

        // An empty token cannot prove ownership; release must refuse to unlink.
        FileLock::release($lock, '');
        $this->assertFileExists($lock, 'empty token must never trigger an unlink');

        FileLock::release($lock, $token);
        $this->assertFileDoesNotExist($lock);
    }

    /**
     * The headline regression: the cross-owner stale-steal unlink.
     *
     * Process A acquires the lock and runs long. Its lock goes stale and is
     * stolen + re-acquired by process B, which now owns the destination and is
     * writing it. A then finishes and calls release() with ITS token. A must NOT
     * delete B's live lock — otherwise a third process C could acquire and write
     * the same destination concurrently with B, defeating the mutex.
     */
    public function testStaleStolenLockIsNotReleasedByOriginalOwner(): void
    {
        $lock = $this->lockPath();

        // A acquires.
        $tokenA = FileLock::acquire($lock, 600);
        $this->assertNotFalse($tokenA, 'A should acquire the lock');

        // A's conversion runs long: backdate the lock past the stale threshold.
        touch($lock, time() - 1000);

        // B sees it as stale, steals it, and re-acquires => fresh, distinct token.
        $tokenB = FileLock::acquire($lock, 600);
        $this->assertNotFalse($tokenB, 'B should steal the stale lock and re-acquire');
        $this->assertNotSame($tokenA, $tokenB, 'B must own a different token than A');
        $this->assertFileExists($lock, 'B now holds the lock');

        // A finally finishes and releases with ITS (now stale) token. This must
        // be a no-op: B still owns the lock.
        FileLock::release($lock, $tokenA);
        $this->assertFileExists($lock, "A's late release must NOT delete B's live lock");

        // The stored token must still be B's (proof the file was untouched).
        $payload = json_decode(file_get_contents($lock), true);
        $this->assertSame($tokenB, $payload['token'] ?? null, 'lock must still belong to B');

        // A third process C must NOT be able to acquire while B legitimately
        // holds a fresh lock — the mutex must hold.
        $this->assertFalse(FileLock::acquire($lock, 600), 'C must not acquire B\'s live lock');

        // B releases cleanly.
        FileLock::release($lock, $tokenB);
        $this->assertFileDoesNotExist($lock, 'B should be able to release its own lock');
    }

    public function testStaleLockIsStolen(): void
    {
        $lock = $this->lockPath();

        // Simulate an abandoned lock left by a crashed process.
        file_put_contents($lock, json_encode(['pid' => 999999, 'time' => 0]));

        // Backdate its mtime well beyond the stale threshold used below.
        $past = time() - 1000;
        touch($lock, $past);

        // With a 600s threshold a 1000s-old lock is stale and must be stolen.
        $token = FileLock::acquire($lock, 600);
        $this->assertNotFalse($token, 'a stale lock should be stolen and re-acquired');

        // After stealing, the lock content should be ours (current pid), not 999999.
        $payload = json_decode(file_get_contents($lock), true);
        $this->assertSame(getmypid(), $payload['pid'], 'stolen lock should be re-stamped with our pid');
        $this->assertSame($token, $payload['token'] ?? null, 'stolen lock should carry our new token');

        FileLock::release($lock, $token);
    }

    public function testFreshLockIsNotStolen(): void
    {
        $lock = $this->lockPath();

        // A just-created lock (current mtime) must NOT be considered stale.
        $token = FileLock::acquire($lock, 600);
        $this->assertNotFalse($token);
        // Second acquire with a generous stale threshold must still fail: not stale.
        $this->assertFalse(FileLock::acquire($lock, 600), 'fresh lock must not be stolen');

        FileLock::release($lock, $token);
    }

    public function testLockContentContainsPid(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertNotFalse($token);

        $raw = file_get_contents($lock);
        $payload = json_decode($raw, true);

        $this->assertIsArray($payload, 'lock payload must be valid JSON object');
        $this->assertArrayHasKey('pid', $payload);
        $this->assertSame(getmypid(), $payload['pid'], 'lock should record the owning pid');
        $this->assertArrayHasKey('time', $payload);
        $this->assertArrayHasKey('token', $payload);
        $this->assertSame($token, $payload['token'], 'lock should record the owner token');

        FileLock::release($lock, $token);
    }

    public function testParentDirIsCreatedWhenMissing(): void
    {
        // Lock path two levels deep, neither dir existing yet.
        $deep = $this->tmpDir . '/a/b/dest.webp.lock';
        $token = FileLock::acquire($deep);
        $this->assertNotFalse($token, 'acquire should create missing parent dirs');
        $this->assertFileExists($deep);

        FileLock::release($deep, $token);
        @rmdir($this->tmpDir . '/a/b');
        @rmdir($this->tmpDir . '/a');
    }

    /**
     * REAL cross-process contention.
     *
     * A child php process acquires the lock and sleeps ~1s while holding it. The
     * parent waits until the child has the lock, then asserts it CANNOT acquire
     * the same lock during the hold window — proving the lock spans separate OS
     * processes (the whole reason for O_EXCL over flock()). Kept fast and
     * deterministic: the parent polls for the child's "ready" marker rather than
     * sleeping a fixed amount, then asserts within the known hold window.
     */
    public function testCrossProcessContention(): void
    {
        $lock = $this->lockPath();
        $readyMarker = $this->tmpDir . '/child-ready';
        $holdSeconds = 1.0;

        $projectRoot = dirname(__DIR__);
        // Child script: acquire the lock via the SAME FileLock class, drop a
        // readiness marker, hold for $holdSeconds, then release and exit.
        $childScript = <<<PHP
<?php
require '{$projectRoot}/lib/classes/FileLock.php';
\$lock = '{$lock}';
\$marker = '{$readyMarker}';
\$token = \\MagicConvert\\FileLock::acquire(\$lock);
if (\$token === false) {
    fwrite(STDERR, "child could not acquire\\n");
    exit(2);
}
file_put_contents(\$marker, (string) getmypid());
usleep((int) ({$holdSeconds} * 1000000));
\\MagicConvert\\FileLock::release(\$lock, \$token);
exit(0);
PHP;

        $childFile = $this->tmpDir . '/child.php';
        file_put_contents($childFile, $childScript);

        $phpBin = PHP_BINARY ?: 'php';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(escapeshellarg($phpBin) . ' ' . escapeshellarg($childFile), $descriptors, $pipes);
        $this->assertIsResource($proc, 'child process should start');

        // Wait (bounded) for the child to signal it holds the lock.
        $deadline = microtime(true) + 5.0;
        while (!file_exists($readyMarker) && microtime(true) < $deadline) {
            usleep(5000); // 5ms
        }
        $this->assertFileExists($readyMarker, 'child should have acquired the lock and signalled readiness');

        // While the child holds it, the parent must NOT be able to acquire.
        $this->assertFalse(
            FileLock::acquire($lock),
            'parent must not acquire a lock held by a separate process'
        );

        // Wait for the child to finish and release.
        $exitCode = proc_close($proc);
        $this->assertSame(0, $exitCode, 'child should exit cleanly after releasing');
        // (stderr/stdout pipes were opened; closing happens with proc_close.)
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        // After the child released, the parent CAN now acquire.
        $parentToken = FileLock::acquire($lock);
        $this->assertNotFalse(
            $parentToken,
            'parent should acquire once the child has released'
        );
        FileLock::release($lock, $parentToken);
    }
}
