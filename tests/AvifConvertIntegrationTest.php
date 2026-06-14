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

    public function testConfigBuiltStackEncodesAValidAvif(): void
    {
        // Prove the CONFIG-DRIVEN stack (AvifStack::fromConverterList, the path the conversion
        // dispatch now uses) is a real, working stack — not just a correctly-ordered object.
        $this->freshStackOrSkip(); // skip on hosts without any AVIF support

        $stack = AvifStack::fromConverterList(array_map(
            static fn ($id) => ['converter' => $id],
            AvifStack::defaultConverterIds()
        ));

        $dest = tempnam(sys_get_temp_dir(), 'mc-avif-cfg-') . '.avif';
        @unlink($dest);
        try {
            $result = $stack->convert(
                $this->fixture('very-small.jpg'),
                $dest,
                ['quality' => 30, 'speed' => 6, 'metadata' => 'none']
            );
            $info = getimagesize($dest);
            $this->assertIsArray($info);
            $this->assertSame('image/avif', $info['mime']);
            $this->assertNotSame('', $result['converter']);
        } finally {
            @unlink($dest);
        }
    }

    public function testDeactivatingTheWinningConverterRemovesItFromTheStack(): void
    {
        // The exact bug the user reported: deactivating a converter must remove it from the AVIF
        // stack. Machine-aware (adapts to whichever backend is operational here) but not flaky.
        $full = $this->freshStackOrSkip();

        $winner = null;
        foreach ($full->selfTest() as $row) {
            if ($row['operational']) {
                $winner = $row['id'];
                break;
            }
        }
        $this->assertNotNull($winner, 'a freshly-operational stack must have at least one working converter');

        // Build the list the UI produces when the user clicks "deactivate" on that converter:
        // default order, all active EXCEPT the winner.
        $list = array_map(static function ($id) use ($winner) {
            $entry = ['converter' => $id];
            if ($id === $winner) {
                $entry['deactivated'] = true;
            }
            return $entry;
        }, AvifStack::defaultConverterIds());

        $filtered = AvifStack::fromConverterList($list);
        $ids = array_map(static fn ($c) => $c->id(), $filtered->converters());

        $this->assertNotContains(
            $winner,
            $ids,
            'a deactivated converter must not appear in the AVIF stack (this is what was broken)'
        );
    }
}
