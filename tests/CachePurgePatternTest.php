<?php

namespace MagicConvert\Tests;

use MagicConvert\CachePurge;
use MagicConvert\OutputFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, data-driven pattern helpers in MagicConvert\CachePurge
 * (Phase 2.1). These are the bits of the purge logic that do NOT touch the
 * filesystem or WordPress and can be exercised in isolation via reflection.
 *
 *  - formatExtAlternation(): builds "(?:webp|avif)" from OutputFormat::all().
 *  - formatForFilename():    maps a converted-artifact filename to its OutputFormat.
 *
 * The point of these tests is to lock in that purge coverage is derived from the
 * OutputFormat registry rather than a hardcoded "\.webp$" — so registering a new
 * format automatically extends purge.
 */
class CachePurgePatternTest extends TestCase
{
    private static function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(CachePurge::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

    // --- formatExtAlternation -----------------------------------------------

    public function testAlternationMatchesAllRegisteredExtensions(): void
    {
        $alt = self::invokePrivate('formatExtAlternation');
        $regex = '#\.' . $alt . '$#';

        foreach (OutputFormat::all() as $format) {
            $this->assertMatchesRegularExpression(
                $regex,
                '/cache/logo.jpg' . $format->dotExtension(),
                'purge alternation must match a ' . $format->id() . ' artifact'
            );
        }
    }

    public function testAlternationMatchesWebpAndAvif(): void
    {
        $alt = self::invokePrivate('formatExtAlternation');
        $regex = '#\.' . $alt . '$#';

        $this->assertMatchesRegularExpression($regex, '/cache/logo.jpg.webp');
        $this->assertMatchesRegularExpression($regex, '/cache/logo.jpg.avif');
    }

    public function testAlternationDoesNotMatchSourceImages(): void
    {
        $alt = self::invokePrivate('formatExtAlternation');
        $regex = '#\.' . $alt . '$#';

        // Originals must never be matched by the purge artifact filter.
        $this->assertDoesNotMatchRegularExpression($regex, '/cache/logo.jpg');
        $this->assertDoesNotMatchRegularExpression($regex, '/cache/logo.png');
        $this->assertDoesNotMatchRegularExpression($regex, '/cache/logo.jpeg');
    }

    // --- formatForFilename ---------------------------------------------------

    public function testFormatForFilenameDetectsWebp(): void
    {
        $format = self::invokePrivate('formatForFilename', ['logo.jpg.webp']);
        $this->assertInstanceOf(OutputFormat::class, $format);
        $this->assertSame('webp', $format->id());
    }

    public function testFormatForFilenameDetectsAvif(): void
    {
        $format = self::invokePrivate('formatForFilename', ['logo.jpg.avif']);
        $this->assertInstanceOf(OutputFormat::class, $format);
        $this->assertSame('avif', $format->id());
    }

    public function testFormatForFilenameReturnsNullForOriginal(): void
    {
        $this->assertNull(self::invokePrivate('formatForFilename', ['logo.jpg']));
        $this->assertNull(self::invokePrivate('formatForFilename', ['logo.png']));
    }
}
