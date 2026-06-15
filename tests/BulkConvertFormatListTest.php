<?php

namespace MagicConvert\Tests;

use MagicConvert\BulkConvert;
use MagicConvert\Config;
use MagicConvert\OutputFormat;
use PHPUnit\Framework\TestCase;

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

    public function testAllFormatsMissingNeedAllFormats(): void
    {
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => false, 'avif' => false],
            1000,
            ['webp', 'avif']
        );
        $this->assertSame(['webp', 'avif'], $needed);
    }

    public function testPartiallyConvertedReportsOnlyMissingFormat(): void
    {
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
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => 2000, 'avif' => 500],
            1000,
            ['webp', 'avif']
        );
        $this->assertSame(['avif'], $needed);
    }

    public function testRespectsEnabledFormatOrderAndSubset(): void
    {
        $needed = BulkConvert::formatsNeedingConversion(
            ['webp' => false, 'avif' => false],
            1000,
            ['webp']
        );
        $this->assertSame(['webp'], $needed);
    }

    public function testWebpOnlyFastPathEmitsPlainStrings(): void
    {
        $this->writeFile('a.jpg', 'jpegdata');
        $this->writeFile('b.png', 'pngdata');
        $this->writeFile('a.jpg.webp', 'webp');

        $listOptions = $this->listOptions(['webp']);
        $results = BulkConvert::getListRecursively('.', $listOptions);

        $this->assertSame(['b.png'], $results);
        foreach ($results as $r) {
            $this->assertIsString($r, 'fast path must emit plain path strings');
        }
    }

    public function testWebpOnlyFastPathExistenceSemanticsUnchanged(): void
    {
        $this->writeFile('old.jpg', 'jpegdata', time());
        $this->writeFile('old.jpg.webp', 'webp', time() - 10000);

        $listOptions = $this->listOptions(['webp']);
        $results = BulkConvert::getListRecursively('.', $listOptions);

        $this->assertSame([], $results, 'fast path is existence-only (byte-for-byte legacy)');
    }

    public function testMultiFormatEmitsOnlyMissingFormats(): void
    {
        $now = time();
        $this->writeFile('c.jpg', 'jpegdata', $now - 100);
        $this->writeFile('c.jpg.webp', 'webp', $now);
        $this->writeFile('d.jpg', 'jpegdata', $now - 100);
        $this->writeFile('e.jpg', 'jpegdata', $now - 100);
        $this->writeFile('e.jpg.webp', 'webp', $now);
        $this->writeFile('e.jpg.avif', 'avif', $now);

        $listOptions = $this->listOptions(['webp', 'avif']);
        $results = BulkConvert::getListRecursively('.', $listOptions);

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

    private function listOptions(array $enabledFormats): array
    {
        return [
            'root' => $this->tmp,
            'ext' => 'append',
            'destination-folder' => 'mingled',
            'webExpressContentDirAbs' => $this->tmp . '/wp-content/magic-convert',
            'uploadDirAbs' => $this->tmp,
            'useDocRootForStructuringCacheDir' => false,
            'imageRoots' => null,
            'enabled-formats' => $enabledFormats,
            'filter' => [
                'only-converted' => false,
                'only-unconverted' => true,
                'image-types' => 3,
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
