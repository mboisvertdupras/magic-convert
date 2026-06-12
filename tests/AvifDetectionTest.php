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

/**
 * Detection-logic tests for the real AVIF converters (Phase 2.3).
 *
 * Capability detection talks to the runtime (loaded extensions, gd_info(),
 * Imagick::queryFormats(), binaries on PATH) which a unit test cannot fully
 * control. What we CAN and MUST assert, on any machine:
 *
 *   1. isOperational() always returns a well-formed {operational:bool, reason:string}
 *      and, when NOT operational, the reason is a non-empty actionable string
 *      (the reason surface is the dominant support generator, so it must never be blank).
 *   2. The reason is SPECIFIC to the missing capability (mentions the extension /
 *      binary / support flag) rather than a generic failure.
 *   3. The binary-path override seam (MAGIC_CONVERT_*_PATH constant/env) is honoured —
 *      this is the one piece of detection we can inject deterministically.
 */
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
            // GD AVIF is actually available here — detection passed, nothing to assert about the reason.
            $this->assertSame('', $op['reason']);
            $this->assertTrue(function_exists('imageavif'));
            return;
        }
        // Not operational: the reason must name GD / imageavif / AVIF Support specifically,
        // not a generic message.
        $this->assertMatchesRegularExpression('/gd|imageavif|avif support/i', $op['reason']);
    }

    public function testExecConverterReasonIsActionableWhenBinaryMissing(): void
    {
        // At least one exec converter should be either operational OR give a reason
        // about exec being disabled / the binary not being found.
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
        // The override seam: a bogus constant path means the binary "resolves" to that
        // path, so discovery does not silently fall back. We assert the resolver picks
        // it up via reflection on the protected resolveBinary().
        if (defined('MAGIC_CONVERT_AVIFENC_PATH')) {
            $this->markTestSkipped('MAGIC_CONVERT_AVIFENC_PATH already defined in this process.');
        }
        // Use the env-var arm of the override (constants cannot be undefined after the test).
        putenv('MAGIC_CONVERT_AVIFENC_PATH=/nonexistent/path/to/avifenc');
        $this->resetExecResolverCache();
        try {
            $c = new AvifEncBinary();
            $ref = new \ReflectionMethod($c, 'resolveBinary');
            $ref->setAccessible(true);
            $resolved = $ref->invoke($c);
            $this->assertSame('/nonexistent/path/to/avifenc', $resolved);
        } finally {
            putenv('MAGIC_CONVERT_AVIFENC_PATH'); // unset
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
            // MagickBinaryAvif memoizes on its own instance fields, so a fresh instance suffices.
            $c = new MagickBinaryAvif();
            $ref = new \ReflectionMethod($c, 'resolveBinary');
            $ref->setAccessible(true);
            $this->assertSame('/custom/magick', $ref->invoke($c));
        } finally {
            putenv('MAGIC_CONVERT_MAGICK_PATH');
        }
    }

    /**
     * Clear the static per-id binary-resolution cache on AbstractAvifExecConverter so an
     * earlier real resolution in this process does not mask an override under test.
     */
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
