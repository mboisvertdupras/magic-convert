<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\ConvertHelperIndependent;

class ConvertDispatchTest extends TestCase
{
    /** @var string[] */
    private $temps = [];

    protected function tearDown(): void
    {
        foreach ($this->temps as $path) {
            foreach ([$path, $path . '.lock'] as $candidate) {
                if (@file_exists($candidate)) {
                    @unlink($candidate);
                }
            }
        }
        $this->temps = [];
    }

    private function fixture(string $name): string
    {
        return MAGIC_CONVERT_TESTS_ROOT . '/test/' . $name;
    }

    private function destFor(string $ext): string
    {
        $dest = tempnam(sys_get_temp_dir(), 'mc-dispatch-') . '.' . $ext;
        @unlink($dest);
        $this->temps[] = $dest;
        return $dest;
    }

    public function testWebpSingleConverterDispatchesProviderEncodeWith(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD lacks imagewebp(), so the WebP gd converter cannot run here.');
        }

        $dest = $this->destFor('webp');
        $result = ConvertHelperIndependent::convert(
            $this->fixture('very-small.jpg'),
            $dest,
            ['quality' => 80, 'metadata' => 'none'],
            null,
            'gd',
            'webp'
        );

        $this->assertTrue($result['success'], 'WebP gd conversion should succeed: ' . $result['msg']);
        $this->assertFileExists($dest);
        $this->assertGreaterThan(0, filesize($dest));
        $this->assertStringContainsString('Converter set to: gd', $result['log']);
    }

    public function testAvifSingleConverterDispatchesProviderEncodeWith(): void
    {
        if (!function_exists('imageavif')) {
            $this->markTestSkipped('GD lacks imageavif(), so the AVIF gd converter cannot run here.');
        }

        $dest = $this->destFor('avif');
        $result = ConvertHelperIndependent::convert(
            $this->fixture('very-small.jpg'),
            $dest,
            ['avif' => ['quality' => 30, 'speed' => 6], 'metadata' => 'none'],
            null,
            'gd',
            'avif'
        );

        $this->assertTrue($result['success'], 'AVIF gd conversion should succeed: ' . $result['msg']);
        $this->assertFileExists($dest);
        $this->assertGreaterThan(0, filesize($dest));
        $this->assertStringContainsString('Converted with: gd', $result['log']);
    }
}
