<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\Avif\AvifStack;

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
        $this->freshStackOrSkip();

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
        $full = $this->freshStackOrSkip();

        $winner = null;
        foreach ($full->selfTest() as $row) {
            if ($row['operational']) {
                $winner = $row['id'];
                break;
            }
        }
        $this->assertNotNull($winner, 'a freshly-operational stack must have at least one working converter');

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
