<?php

namespace MagicConvert\Tests;

use MagicConvert\RestApi;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the WordPress-independent, format-aware REST helpers (Phase 2.5):
 *
 *   - resolveRequestedFormat()  validate/normalise the /convert 'format' param against BOTH the
 *                               known OutputFormat ids AND the enabled-formats allow-list.
 *   - flattenList()             grouped -> flat { root, path, formats:[...] } (both file shapes).
 *   - formatTotals()            per-format pending counts (work estimate / progress denominators).
 *   - formatsInList()           the formats present in a persisted list (paged re-derivation).
 */
class RestApiFormatTest extends TestCase
{
    // --- resolveRequestedFormat ----------------------------------------------

    public function testAbsentFormatDefaultsToWebp(): void
    {
        $this->assertSame('webp', RestApi::resolveRequestedFormat(null, ['webp', 'avif']));
        $this->assertSame('webp', RestApi::resolveRequestedFormat('', ['webp', 'avif']));
    }

    public function testEnabledKnownFormatIsAccepted(): void
    {
        $this->assertSame('avif', RestApi::resolveRequestedFormat('avif', ['webp', 'avif']));
        $this->assertSame('avif', RestApi::resolveRequestedFormat('AVIF', ['webp', 'avif'])); // case-insensitive
        $this->assertSame('webp', RestApi::resolveRequestedFormat(' webp ', ['webp', 'avif'])); // trimmed
    }

    public function testUnknownFormatIsRejected(): void
    {
        $this->assertNull(RestApi::resolveRequestedFormat('jpegxl', ['webp', 'avif']));
        $this->assertNull(RestApi::resolveRequestedFormat('../etc/passwd', ['webp', 'avif']));
        $this->assertNull(RestApi::resolveRequestedFormat(['avif'], ['webp', 'avif'])); // non-scalar
    }

    public function testKnownButDisabledFormatIsRejected(): void
    {
        // 'avif' is a real OutputFormat, but it is not enabled here => rejected.
        $this->assertNull(RestApi::resolveRequestedFormat('avif', ['webp']));
    }

    // --- flattenList ----------------------------------------------------------

    public function testFlattenLegacyStringItemsDefaultToEnabledFormats(): void
    {
        $groups = [
            ['groupName' => 'uploads', 'files' => ['2021/a.jpg', '2021/b.png']],
        ];
        $flat = RestApi::flattenList($groups, ['webp']);
        $this->assertSame([
            ['root' => 'uploads', 'path' => '2021/a.jpg', 'formats' => ['webp']],
            ['root' => 'uploads', 'path' => '2021/b.png', 'formats' => ['webp']],
        ], $flat);
    }

    public function testFlattenMultiFormatItemsCarryTheirOwnMissingFormats(): void
    {
        $groups = [
            ['groupName' => 'uploads', 'files' => [
                ['path' => 'a.jpg', 'formats' => ['avif']],            // only avif missing
                ['path' => 'b.jpg', 'formats' => ['webp', 'avif']],    // both missing
            ]],
        ];
        $flat = RestApi::flattenList($groups, ['webp', 'avif']);
        $this->assertSame([
            ['root' => 'uploads', 'path' => 'a.jpg', 'formats' => ['avif']],
            ['root' => 'uploads', 'path' => 'b.jpg', 'formats' => ['webp', 'avif']],
        ], $flat);
    }

    public function testFlattenHandlesNonArrayGracefully(): void
    {
        $this->assertSame([], RestApi::flattenList(null));
        $this->assertSame([], RestApi::flattenList([]));
    }

    // --- formatTotals ---------------------------------------------------------

    public function testFormatTotalsCountsEachFormatIndependently(): void
    {
        $flat = [
            ['root' => 'u', 'path' => 'a', 'formats' => ['webp', 'avif']],
            ['root' => 'u', 'path' => 'b', 'formats' => ['avif']],
            ['root' => 'u', 'path' => 'c', 'formats' => ['webp']],
        ];
        $this->assertSame(['webp' => 2, 'avif' => 2], RestApi::formatTotals($flat, ['webp', 'avif']));
    }

    public function testFormatTotalsAlwaysHasAKeyPerEnabledFormatEvenAtZero(): void
    {
        $flat = [
            ['root' => 'u', 'path' => 'a', 'formats' => ['webp']],
        ];
        $this->assertSame(['webp' => 1, 'avif' => 0], RestApi::formatTotals($flat, ['webp', 'avif']));
    }

    // --- formatsInList --------------------------------------------------------

    public function testFormatsInListReturnsRegistryOrderedUnion(): void
    {
        $flat = [
            ['root' => 'u', 'path' => 'a', 'formats' => ['avif']],
            ['root' => 'u', 'path' => 'b', 'formats' => ['webp']],
        ];
        // Registry order is webp, then avif — regardless of first appearance.
        $this->assertSame(['webp', 'avif'], RestApi::formatsInList($flat));
    }

    public function testFormatsInListFallsBackToWebpForEmptyOrLegacyList(): void
    {
        $this->assertSame(['webp'], RestApi::formatsInList([]));
        $this->assertSame(['webp'], RestApi::formatsInList(null));
    }
}
