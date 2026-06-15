<?php

namespace MagicConvert\Tests;

use MagicConvert\FileLock;
use PHPUnit\Framework\TestCase;

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

        $token2 = FileLock::acquire($lock);
        $this->assertNotFalse($token2, 'acquire after release should succeed');
        FileLock::release($lock, $token2);
    }

    public function testAcquireReturnsOpaqueToken(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertIsString($token, 'acquire should return a token string on success');
        $this->assertNotSame('', $token, 'token must be non-empty');
        $this->assertNotSame(true, $token, 'acquire must not return a bare bool true');

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
        $this->assertFalse(FileLock::acquire($lock), 'acquire while held must fail');

        FileLock::release($lock, $token);
    }

    public function testReleaseIsSafeWhenLockMissing(): void
    {
        FileLock::release($this->lockPath(), 'some-token');
        $this->assertFileDoesNotExist($this->lockPath());
    }

    public function testReleaseWithWrongTokenDoesNotUnlink(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertNotFalse($token);

        FileLock::release($lock, $token . '-not-mine');
        $this->assertFileExists($lock, 'lock must survive a release with a non-matching token');

        $this->assertFalse(FileLock::acquire($lock), 'lock must remain held after a wrong-token release');

        FileLock::release($lock, $token);
        $this->assertFileDoesNotExist($lock);
    }

    public function testReleaseWithEmptyTokenDoesNotUnlink(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock);
        $this->assertNotFalse($token);

        FileLock::release($lock, '');
        $this->assertFileExists($lock, 'empty token must never trigger an unlink');

        FileLock::release($lock, $token);
        $this->assertFileDoesNotExist($lock);
    }

    public function testStaleStolenLockIsNotReleasedByOriginalOwner(): void
    {
        $lock = $this->lockPath();

        $tokenA = FileLock::acquire($lock, 600);
        $this->assertNotFalse($tokenA, 'A should acquire the lock');

        touch($lock, time() - 1000);

        $tokenB = FileLock::acquire($lock, 600);
        $this->assertNotFalse($tokenB, 'B should steal the stale lock and re-acquire');
        $this->assertNotSame($tokenA, $tokenB, 'B must own a different token than A');
        $this->assertFileExists($lock, 'B now holds the lock');

        FileLock::release($lock, $tokenA);
        $this->assertFileExists($lock, "A's late release must NOT delete B's live lock");

        $payload = json_decode(file_get_contents($lock), true);
        $this->assertSame($tokenB, $payload['token'] ?? null, 'lock must still belong to B');

        $this->assertFalse(FileLock::acquire($lock, 600), 'C must not acquire B\'s live lock');

        FileLock::release($lock, $tokenB);
        $this->assertFileDoesNotExist($lock, 'B should be able to release its own lock');
    }

    public function testStaleLockIsStolen(): void
    {
        $lock = $this->lockPath();

        file_put_contents($lock, json_encode(['pid' => 999999, 'time' => 0]));

        $past = time() - 1000;
        touch($lock, $past);

        $token = FileLock::acquire($lock, 600);
        $this->assertNotFalse($token, 'a stale lock should be stolen and re-acquired');

        $payload = json_decode(file_get_contents($lock), true);
        $this->assertSame(getmypid(), $payload['pid'], 'stolen lock should be re-stamped with our pid');
        $this->assertSame($token, $payload['token'] ?? null, 'stolen lock should carry our new token');

        FileLock::release($lock, $token);
    }

    public function testFreshLockIsNotStolen(): void
    {
        $lock = $this->lockPath();

        $token = FileLock::acquire($lock, 600);
        $this->assertNotFalse($token);
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
        $deep = $this->tmpDir . '/a/b/dest.webp.lock';
        $token = FileLock::acquire($deep);
        $this->assertNotFalse($token, 'acquire should create missing parent dirs');
        $this->assertFileExists($deep);

        FileLock::release($deep, $token);
        @rmdir($this->tmpDir . '/a/b');
        @rmdir($this->tmpDir . '/a');
    }

    public function testCrossProcessContention(): void
    {
        $lock = $this->lockPath();
        $readyMarker = $this->tmpDir . '/child-ready';
        $holdSeconds = 1.0;

        $projectRoot = dirname(__DIR__);
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

        $deadline = microtime(true) + 5.0;
        while (!file_exists($readyMarker) && microtime(true) < $deadline) {
            usleep(5000);
        }
        $this->assertFileExists($readyMarker, 'child should have acquired the lock and signalled readiness');

        $this->assertFalse(
            FileLock::acquire($lock),
            'parent must not acquire a lock held by a separate process'
        );

        $exitCode = proc_close($proc);
        $this->assertSame(0, $exitCode, 'child should exit cleanly after releasing');
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        $parentToken = FileLock::acquire($lock);
        $this->assertNotFalse(
            $parentToken,
            'parent should acquire once the child has released'
        );
        FileLock::release($lock, $parentToken);
    }
}
