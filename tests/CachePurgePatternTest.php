<?php

namespace MagicConvert\Tests;

use MagicConvert\CachePurge;
use MagicConvert\OutputFormat;
use PHPUnit\Framework\TestCase;

class CachePurgePatternTest extends TestCase
{
    private static function invokePrivate(string $method, array $args = [])
    {
        $ref = new \ReflectionMethod(CachePurge::class, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs(null, $args);
    }

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

        $this->assertDoesNotMatchRegularExpression($regex, '/cache/logo.jpg');
        $this->assertDoesNotMatchRegularExpression($regex, '/cache/logo.png');
        $this->assertDoesNotMatchRegularExpression($regex, '/cache/logo.jpeg');
    }

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
