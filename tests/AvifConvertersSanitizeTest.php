<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\Avif\AvifStack;

require_once dirname(__DIR__) . '/lib/options/avif-converters-sanitize.php';

/**
 * Tests for magicconvert_sanitizeAvifConverters() — the pure sanitizer behind
 * $_POST['avif-converters'] in lib/options/submit.php.
 *
 * submit.php is a procedural admin-post handler that runs the save flow at file scope and so cannot
 * be require()'d here; the sanitizer therefore lives in its own side-effect-free file
 * (lib/options/avif-converters-sanitize.php), which both submit.php and this test include. This
 * tests the REAL function (no mirrored copy, no drift).
 *
 * The known id space is the AVIF converter stack: imagick, vips, gd, magick-binary, avifenc, cavif.
 */
class AvifConvertersSanitizeTest extends TestCase
{
    private function known(): array
    {
        return AvifStack::defaultConverterIds();
    }

    private function sanitize($posted): array
    {
        return magicconvert_sanitizeAvifConverters($posted, $this->known());
    }

    public function testValidOrderedListPassesThroughPreservingOrder(): void
    {
        $posted = [
            ['converter' => 'avifenc'],
            ['converter' => 'gd'],
            ['converter' => 'imagick'],
        ];
        $this->assertSame($posted, $this->sanitize($posted));
    }

    public function testUnknownConverterIdsAreDropped(): void
    {
        $posted = [
            ['converter' => 'imagick'],
            ['converter' => 'cwebp'],   // a WebP id — NOT valid for AVIF
            ['converter' => 'bogus'],
            ['converter' => 'cavif'],
        ];
        $this->assertSame(
            [['converter' => 'imagick'], ['converter' => 'cavif']],
            $this->sanitize($posted)
        );
    }

    public function testDeactivatedTrueIsPreservedAndFalseOrAbsentIsOmitted(): void
    {
        $posted = [
            ['converter' => 'imagick', 'deactivated' => true],
            ['converter' => 'vips', 'deactivated' => false],
            ['converter' => 'gd'],
            ['converter' => 'avifenc', 'deactivated' => 'yes'], // truthy-but-not-strictly-true -> omitted
        ];
        $this->assertSame(
            [
                ['converter' => 'imagick', 'deactivated' => true],
                ['converter' => 'vips'],
                ['converter' => 'gd'],
                ['converter' => 'avifenc'],
            ],
            $this->sanitize($posted)
        );
    }

    public function testDuplicateIdsCollapseToFirstOccurrence(): void
    {
        $posted = [
            ['converter' => 'vips'],
            ['converter' => 'gd'],
            ['converter' => 'vips', 'deactivated' => true], // duplicate -> dropped entirely
        ];
        $this->assertSame(
            [['converter' => 'vips'], ['converter' => 'gd']],
            $this->sanitize($posted)
        );
    }

    public function testNonArrayInputYieldsEmptyList(): void
    {
        $this->assertSame([], $this->sanitize(null));
        $this->assertSame([], $this->sanitize('nonsense'));
        $this->assertSame([], $this->sanitize(42));
        $this->assertSame([], $this->sanitize(false));
    }

    public function testItemsMissingConverterKeyAreSkipped(): void
    {
        $posted = [
            ['deactivated' => true],
            'just-a-string',
            ['converter' => 'gd'],
            42,
        ];
        $this->assertSame([['converter' => 'gd']], $this->sanitize($posted));
    }

    public function testExtraKeysAreStrippedFromEachEntry(): void
    {
        // The UI may include transient fields (id/working/error) or stray options; only
        // {converter[,deactivated]} survive.
        $posted = [
            [
                'converter' => 'imagick',
                'id' => 'imagick',
                'working' => true,
                'error' => '',
                'options' => ['use-nice' => true],
            ],
            [
                'converter' => 'cavif',
                'deactivated' => true,
                'warnings' => ['x'],
            ],
        ];
        $this->assertSame(
            [
                ['converter' => 'imagick'],
                ['converter' => 'cavif', 'deactivated' => true],
            ],
            $this->sanitize($posted)
        );
    }

    public function testEmptyArrayYieldsEmptyList(): void
    {
        $this->assertSame([], $this->sanitize([]));
    }

    public function testFullDefaultListRoundTripsUnchanged(): void
    {
        // The default stack (all active, priority order) must survive sanitization byte-for-byte,
        // so saving an untouched AVIF section never mutates the config.
        $default = array_map(
            static fn ($id) => ['converter' => $id],
            AvifStack::defaultConverterIds()
        );
        $this->assertSame($default, $this->sanitize($default));
    }
}
