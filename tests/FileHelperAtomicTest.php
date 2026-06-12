<?php

namespace MagicConvert\Tests;

use MagicConvert\FileHelper;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MagicConvert\FileHelper::atomicPutContents() — the write-to-temp +
 * rename helper used for every config/options/state JSON write and the
 * conversion log files.
 *
 * The "never partial" guarantee comes from rename() within one directory being
 * atomic on POSIX; we cannot easily force a torn write in a unit test, so we
 * assert the observable contract instead:
 *  - the final file ends up with exactly the new content,
 *  - an existing file is replaced wholesale (old-or-new, never a mix),
 *  - no stray ".tmp" files are left behind on success,
 *  - the original is untouched when the destination dir is unwritable (failure).
 */
class FileHelperAtomicTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/mc-atomic-' . getmypid() . '-' . uniqid('', true);
        mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            foreach (scandir($this->tmpDir) as $f) {
                if ($f === '.' || $f === '..') {
                    continue;
                }
                @chmod($this->tmpDir . '/' . $f, 0664);
                @unlink($this->tmpDir . '/' . $f);
            }
            @chmod($this->tmpDir, 0775);
            @rmdir($this->tmpDir);
        }
    }

    /** Names of any leftover ".tmp" files in the temp dir. */
    private function leftoverTempFiles(): array
    {
        $found = [];
        foreach (scandir($this->tmpDir) as $f) {
            if (substr($f, -4) === '.tmp') {
                $found[] = $f;
            }
        }
        return $found;
    }

    public function testWritesNewFileWithExactContent(): void
    {
        $path = $this->tmpDir . '/config.json';
        $contents = '{"a":1,"b":"two"}';

        $this->assertTrue(FileHelper::atomicPutContents($path, $contents));
        $this->assertSame($contents, file_get_contents($path));
        $this->assertSame([], $this->leftoverTempFiles(), 'no temp files should remain after success');
    }

    public function testReplacesExistingFileWholesale(): void
    {
        $path = $this->tmpDir . '/config.json';
        file_put_contents($path, 'OLD-CONTENT-OLD-CONTENT');

        $new = 'NEW';
        $this->assertTrue(FileHelper::atomicPutContents($path, $new));

        // Must be exactly the new content — not a concatenation or a prefix of old.
        $this->assertSame($new, file_get_contents($path));
        $this->assertSame([], $this->leftoverTempFiles());
    }

    public function testEmptyContentIsWritten(): void
    {
        $path = $this->tmpDir . '/empty.md';
        $this->assertTrue(FileHelper::atomicPutContents($path, ''));
        $this->assertSame('', file_get_contents($path));
        $this->assertSame([], $this->leftoverTempFiles());
    }

    public function testPreservesExistingFilePermissions(): void
    {
        if (FileHelper::isWindows()) {
            $this->markTestSkipped('POSIX permission semantics not applicable on Windows');
        }
        $path = $this->tmpDir . '/perm.json';
        file_put_contents($path, 'orig');
        chmod($path, 0640);
        clearstatcache();

        $this->assertTrue(FileHelper::atomicPutContents($path, 'updated'));

        clearstatcache();
        $mode = substr(sprintf('%o', fileperms($path)), -4);
        $this->assertSame('0640', $mode, 'existing file mode should be preserved across the atomic replace');
        $this->assertSame('updated', file_get_contents($path));
    }

    public function testFailureLeavesOriginalIntactAndNoTempLeftovers(): void
    {
        if (FileHelper::isWindows()) {
            $this->markTestSkipped('chmod-based unwritable-dir simulation is unreliable on Windows');
        }
        // Some environments (root in CI/containers) ignore directory write bits.
        // Skip rather than report a false failure if we can still write.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('running as root: directory permission enforcement is bypassed');
        }

        $path = $this->tmpDir . '/locked.json';
        file_put_contents($path, 'ORIGINAL');

        // Make the directory read+execute only (no write) so the temp file cannot
        // be created. atomicPutContents must fail cleanly and not touch the original.
        chmod($this->tmpDir, 0555);
        clearstatcache();

        $result = FileHelper::atomicPutContents($path, 'SHOULD-NOT-APPEAR');

        // Restore write so we can inspect/cleanup.
        chmod($this->tmpDir, 0775);
        clearstatcache();

        $this->assertFalse($result, 'atomicPutContents should report failure when temp cannot be created');
        $this->assertSame('ORIGINAL', file_get_contents($path), 'original content must be untouched on failure');
        $this->assertSame([], $this->leftoverTempFiles(), 'no temp file should be left behind on failure');
    }

    public function testSaveJSONOptionsUsesAtomicWrite(): void
    {
        $path = $this->tmpDir . '/wod-options.json';
        $obj = ['serve' => ['x' => 1], 'list' => [1, 2, 3]];

        $this->assertTrue(FileHelper::saveJSONOptions($path, $obj));

        $reloaded = FileHelper::loadJSONOptions($path);
        $this->assertSame($obj, $reloaded, 'round-trips through the atomic JSON writer');
        $this->assertSame([], $this->leftoverTempFiles());
    }
}
