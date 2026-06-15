<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\Avif\AbstractAvifConverter;
use MagicConvert\Avif\ImagickAvif;
use MagicConvert\Avif\VipsAvif;
use MagicConvert\Avif\GdAvif;
use MagicConvert\Avif\MagickBinaryAvif;
use MagicConvert\Avif\AvifEncBinary;
use MagicConvert\Avif\CavifBinary;

class AvifDetectionTest extends TestCase
{
    /**
     * @return AbstractAvifConverter[]
     */
    private function allConverters(): array
    {
        return [
            new ImagickAvif(),
            new VipsAvif(),
            new GdAvif(),
            new MagickBinaryAvif(),
            new AvifEncBinary(),
            new CavifBinary(),
        ];
    }

    public function testEveryConverterReportsWellFormedOperationality(): void
    {
        foreach ($this->allConverters() as $c) {
            $op = $c->isOperational();
            $this->assertIsArray($op, $c->id() . ' must return an array');
            $this->assertArrayHasKey('operational', $op, $c->id() . ' must report operational');
            $this->assertArrayHasKey('reason', $op, $c->id() . ' must report reason');
            $this->assertIsBool($op['operational']);
            $this->assertIsString($op['reason']);

            if ($op['operational'] === false) {
                $this->assertNotSame(
                    '',
                    trim($op['reason']),
                    $c->id() . ' must give a non-empty reason when not operational'
                );
            }
        }
    }

    public function testImagickReasonMentionsImagickWhenAbsent(): void
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('imagick is loaded on this machine; cannot assert the absence reason.');
        }
        $op = (new ImagickAvif())->isOperational();
        $this->assertFalse($op['operational']);
        $this->assertStringContainsStringIgnoringCase('imagick', $op['reason']);
    }

    public function testVipsReasonMentionsVipsWhenAbsent(): void
    {
        if (extension_loaded('vips')) {
            $this->markTestSkipped('vips is loaded on this machine; cannot assert the absence reason.');
        }
        $op = (new VipsAvif())->isOperational();
        $this->assertFalse($op['operational']);
        $this->assertStringContainsStringIgnoringCase('vips', $op['reason']);
    }

    public function testGdReasonMentionsGdOrAvifSupportWhenUnavailable(): void
    {
        $op = (new GdAvif())->isOperational();
        if ($op['operational']) {
            $this->assertSame('', $op['reason']);
            $this->assertTrue(function_exists('imageavif'));
            return;
        }
        $this->assertMatchesRegularExpression('/gd|imageavif|avif support/i', $op['reason']);
    }

    public function testExecConverterReasonIsActionableWhenBinaryMissing(): void
    {
        foreach ([new AvifEncBinary(), new CavifBinary(), new MagickBinaryAvif()] as $c) {
            $op = $c->isOperational();
            if ($op['operational']) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/not found|not.*PATH|cannot execute|disabled|no AVIF|return code|failed/i',
                $op['reason'],
                $c->id() . ' reason should explain the missing capability'
            );
        }
    }

    public function testAvifEncHonoursPathOverrideConstant(): void
    {
        if (defined('MAGIC_CONVERT_AVIFENC_PATH')) {
            $this->markTestSkipped('MAGIC_CONVERT_AVIFENC_PATH already defined in this process.');
        }
        putenv('MAGIC_CONVERT_AVIFENC_PATH=/nonexistent/path/to/avifenc');
        $this->resetExecResolverCache();
        try {
            $c = new AvifEncBinary();
            $ref = new \ReflectionMethod($c, 'resolveBinary');
            $ref->setAccessible(true);
            $resolved = $ref->invoke($c);
            $this->assertSame('/nonexistent/path/to/avifenc', $resolved);
        } finally {
            putenv('MAGIC_CONVERT_AVIFENC_PATH');
            $this->resetExecResolverCache();
        }
    }

    public function testMagickHonoursPathOverrideEnv(): void
    {
        if (defined('MAGIC_CONVERT_MAGICK_PATH')) {
            $this->markTestSkipped('MAGIC_CONVERT_MAGICK_PATH already defined in this process.');
        }
        putenv('MAGIC_CONVERT_MAGICK_PATH=/custom/magick');
        try {
            $c = new MagickBinaryAvif();
            $ref = new \ReflectionMethod($c, 'resolveBinary');
            $ref->setAccessible(true);
            $this->assertSame('/custom/magick', $ref->invoke($c));
        } finally {
            putenv('MAGIC_CONVERT_MAGICK_PATH');
        }
    }

    private function resetExecResolverCache(): void
    {
        $prop = new \ReflectionProperty(
            \MagicConvert\Avif\AbstractAvifExecConverter::class,
            'resolvedBinary'
        );
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}
