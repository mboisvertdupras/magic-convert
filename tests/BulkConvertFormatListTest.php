<?php

namespace MagicConvert\Tests;

use MagicConvert\BulkConvert;
use MagicConvert\Config;
use MagicConvert\OutputFormat;
use PHPUnit\Framework\TestCase;

/**
 * Format-aware listing tests (Phase 2.5).
 *
 * Two layers:
 *
 *  1. The PURE per-file decision: BulkConvert::formatsNeedingConversion() — given a source
 *     mtime + per-format destination mtimes, which enabled formats still need conversion
 *     (missing OR stale)? No filesystem.
 *
 *  2. An INTEGRATION pass over a real temp directory tree exercising getListRecursively():
 *       - a webp-only config takes the fast path and emits PLAIN PATH STRINGS (byte-for-byte
 *         unchanged shape), and
 *       - a webp+avif config emits { path, formats:[...] } items carrying ONLY the missing
 *         formats, with fully-converted files excluded.
 *
 *     The temp tree uses 'mingled' destinations (cache file sits next to the source as
 *     "<source>.<ext>"), which makes the destination path deterministic without WordPress.
 */
class BulkConvertFormatListTest extends TestCase
{
    /** @var string */
    private $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/mc-bulk-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmp);
    }

    // --- formatsNeedingConversion (pure) --------------------------------------

    public function testAllFormatsMissingNeedAllFormats(): void
    {
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => false, 'avif' => false],   // both destinations missing
            1000,                                  // source mtime
            ['webp', 'avif']
        );
        $this->assertSame(['webp', 'avif'], $needed);
    }

    public function testPartiallyConvertedReportsOnlyMissingFormat(): void
    {
        // webp is fresh (>= source), avif is missing => only avif is needed.
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => 1500, 'avif' => false],
            1000,
            ['webp', 'avif']
        );
        $this->assertSame(['avif'], $needed);
    }

    public function testFullyConvertedNeedsNothing(): void
    {
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => 1500, 'avif' => 1500],
            1000,
            ['webp', 'avif']
        );
        $this->assertSame([], $needed);
    }

    public function testStaleDestinationIsTreatedAsMissing(): void
    {
        // avif destination is OLDER than the source (stale) => still needs converting.
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => 2000, 'avif' => 500],
            1000,
            ['webp', 'avif']
        );
        $this->assertSame(['avif'], $needed);
    }

    public function testRespectsEnabledFormatOrderAndSubset(): void
    {
        // Only webp enabled: avif is ignored even if its destination is absent.
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => false, 'avif' => false],
            1000,
            ['webp']
        );
        $this->assertSame(['webp'], $needed);
    }

    // --- getListRecursively fast path (webp only) -----------------------------

    public function testWebpOnlyFastPathEmitsPlainStrings(): void
    {
        $this->writeFile('a.jpg', 'jpegdata');
        $this->writeFile('b.png', 'pngdata');
        // a.jpg already has its webp; b.png does not.
        $this->writeFile('a.jpg.webp', 'webp');

        $listOptions = $this->listOptions(['webp']);
        $results = BulkConvert::getListRecursively('.', $listOptions);

        // Fast path => plain strings, only the unconverted file.
        $this->assertSame(['b.png'], $results);
        foreach ($results as $r) {
            $this->assertIsString($r, 'fast path must emit plain path strings');
        }
    }

    public function testWebpOnlyFastPathExistenceSemanticsUnchanged(): void
    {
        // A webp that is OLDER than the source still counts as "converted" in the fast path
        // (legacy existence-only semantics), so the file is NOT listed.
        $this->writeFile('old.jpg', 'jpegdata', time());
        $this->writeFile('old.jpg.webp', 'webp', time() - 10000);   // stale, but exists

        $listOptions = $this->listOptions(['webp']);
        $results = BulkConvert::getListRecursively('.', $listOptions);

        $this->assertSame([], $results, 'fast path is existence-only (byte-for-byte legacy)');
    }

    // --- getListRecursively multi-format path (webp + avif) -------------------

    public function testMultiFormatEmitsOnlyMissingFormats(): void
    {
        $now = time();
        // c.jpg: webp exists+fresh, avif missing  => formats: ['avif']
        $this->writeFile('c.jpg', 'jpegdata', $now - 100);
        $this->writeFile('c.jpg.webp', 'webp', $now);
        // d.jpg: nothing converted                => formats: ['webp','avif']
        $this->writeFile('d.jpg', 'jpegdata', $now - 100);
        // e.jpg: fully converted                  => EXCLUDED
        $this->writeFile('e.jpg', 'jpegdata', $now - 100);
        $this->writeFile('e.jpg.webp', 'webp', $now);
        $this->writeFile('e.jpg.avif', 'avif', $now);

        $listOptions = $this->listOptions(['webp', 'avif']);
        $results = BulkConvert::getListRecursively('.', $listOptions);

        // Index by path for order-independent assertions.
        $byPath = [];
        foreach ($results as $item) {
            $this->assertIsArray($item, 'multi-format path must emit array items');
            $this->assertArrayHasKey('path', $item);
            $this->assertArrayHasKey('formats', $item);
            $byPath[$item['path']] = $item['formats'];
        }

        $this->assertArrayNotHasKey('e.jpg', $byPath, 'fully-converted file must be excluded');
        $this->assertSame(['avif'], $byPath['c.jpg']);
        $this->assertSame(['webp', 'avif'], $byPath['d.jpg']);
        $this->assertCount(2, $byPath);
    }

    // --- helpers --------------------------------------------------------------

    private function listOptions(array $enabledFormats): array
    {
        return [
            'root' => $this->tmp,
            'ext' => 'append',
            'destination-folder' => 'mingled',
            'webExpressContentDirAbs' => $this->tmp . '/wp-content/magic-convert',
            'uploadDirAbs' => $this->tmp,    // makes mingled apply => dest is next to source
            'useDocRootForStructuringCacheDir' => false,
            'imageRoots' => null,
            'enabled-formats' => $enabledFormats,
            'filter' => [
                'only-converted' => false,
                'only-unconverted' => true,
                'image-types' => 3,          // jpeg + png
                'max-depth' => 100,
            ],
            'flattenList' => true,
        ];
    }

    private function writeFile(string $rel, string $contents, ?int $mtime = null): void
    {
        $path = $this->tmp . '/' . $rel;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
        if ($mtime !== null) {
            touch($path, $mtime);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
