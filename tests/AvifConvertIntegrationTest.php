<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\Avif\AvifStack;

/**
 * Integration test: actually encode an AVIF with the real stack on this machine.
 *
 * This is the ONE test that performs real encoding. It SELF-SKIPS (markTestSkipped)
 * when no converter in the stack is operational, so CI on a host without AVIF
 * support stays green while a capable host genuinely validates the encode.
 *
 * On a machine WITH a converter, it asserts:
 *   - a real file is produced,
 *   - the bytes are a valid AVIF (getimagesize reports image/avif),
 *   - both a JPEG source and a PNG-with-alpha source encode.
 */
class AvifConvertIntegrationTest extends TestCase
{
    private function fixture(string $name): string
    {
        return MAGIC_CONVERT_TESTS_ROOT . '/test/' . $name;
    }

    private function freshStackOrSkip(): AvifStack
    {
        $stack = new AvifStack();
        if (!$stack->isOperational()) {
            $reasons = [];
            foreach ($stack->selfTest() as $row) {
                $reasons[] = $row['id'] . ': ' . $row['reason'];
            }
            $this->markTestSkipped(
                "No AVIF converter is operational on this machine, so real encoding is skipped.\n  "
                . implode("\n  ", $reasons)
            );
        }
        return $stack;
    }

    public function testEncodesJpegToValidAvif(): void
    {
        $stack = $this->freshStackOrSkip();

        $dest = tempnam(sys_get_temp_dir(), 'mc-avif-') . '.avif';
        @unlink($dest);
        try {
            $result = $stack->convert(
                $this->fixture('very-small.jpg'),
                $dest,
                ['quality' => 30, 'speed' => 6, 'metadata' => 'none']
            );

            $this->assertNotSame('', $result['converter']);
            $this->assertFileExists($dest);
            $this->assertGreaterThan(0, filesize($dest));

            $info = getimagesize($dest);
            $this->assertIsArray($info, 'getimagesize should parse the output');
            $this->assertSame('image/avif', $info['mime'], 'output must be a valid AVIF');

            // Report which converter actually did the work (visible with --debug / -v).
            fwrite(STDERR, "\n[AvifConvertIntegrationTest] JPEG encoded by: " . $result['converter']
                . ' (' . filesize($dest) . " bytes)\n");
        } finally {
            @unlink($dest);
        }
    }

    public function testEncodesPngWithAlphaToValidAvif(): void
    {
        $stack = $this->freshStackOrSkip();

        $dest = tempnam(sys_get_temp_dir(), 'mc-avif-alpha-') . '.avif';
        @unlink($dest);
        try {
            $result = $stack->convert(
                $this->fixture('alphatest.png'),
                $dest,
                ['quality' => 30, 'speed' => 6, 'metadata' => 'all']
            );

            $this->assertFileExists($dest);
            $info = getimagesize($dest);
            $this->assertIsArray($info);
            $this->assertSame('image/avif', $info['mime']);

            fwrite(STDERR, "[AvifConvertIntegrationTest] PNG(alpha) encoded by: " . $result['converter']
                . ' (' . filesize($dest) . " bytes)\n");
        } finally {
            @unlink($dest);
        }
    }
}
