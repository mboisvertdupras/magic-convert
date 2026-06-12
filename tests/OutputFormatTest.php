<?php

namespace MagicConvert\Tests;

use MagicConvert\OutputFormat;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the MagicConvert\OutputFormat value object + registry
 * introduced in Phase 2.1 (multi-format core).
 *
 * Pure logic, no filesystem / WordPress dependency.
 */
class OutputFormatTest extends TestCase
{
    // --- registry lookups ----------------------------------------------------

    public function testByIdReturnsWebpFormat(): void
    {
        $webp = OutputFormat::byId('webp');
        $this->assertSame('webp', $webp->id());
        $this->assertSame('webp', $webp->extension());
        $this->assertSame('.webp', $webp->dotExtension());
        $this->assertSame('image/webp', $webp->mimeType());
        $this->assertSame('webp-images', $webp->cacheDirName());
    }

    public function testByIdReturnsAvifFormat(): void
    {
        $avif = OutputFormat::byId('avif');
        $this->assertSame('avif', $avif->id());
        $this->assertSame('avif', $avif->extension());
        $this->assertSame('.avif', $avif->dotExtension());
        $this->assertSame('image/avif', $avif->mimeType());
        $this->assertSame('avif-images', $avif->cacheDirName());
    }

    public function testByIdThrowsOnUnknownId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutputFormat::byId('jxl');
    }

    public function testByIdThrowsOnEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutputFormat::byId('');
    }

    // --- registry enumeration ------------------------------------------------

    public function testIdsContainsBothRegisteredFormats(): void
    {
        $ids = OutputFormat::ids();
        $this->assertContains('webp', $ids);
        $this->assertContains('avif', $ids);
    }

    public function testAllReturnsOutputFormatInstances(): void
    {
        $all = OutputFormat::all();
        $this->assertNotEmpty($all);
        foreach ($all as $format) {
            $this->assertInstanceOf(OutputFormat::class, $format);
        }
        // Same cardinality as ids().
        $this->assertCount(count(OutputFormat::ids()), $all);
    }

    public function testWebpIsTheFirstRegisteredFormat(): void
    {
        // webp must come first so data-driven consumers default to it and so the
        // default convert path is unchanged.
        $ids = OutputFormat::ids();
        $this->assertSame('webp', $ids[0]);
    }

    // --- default / convenience accessors -------------------------------------

    public function testWebpAccessorReturnsDefaultFormat(): void
    {
        $this->assertSame('webp', OutputFormat::webp()->id());
        $this->assertTrue(OutputFormat::webp()->isDefault());
        $this->assertFalse(OutputFormat::byId('avif')->isDefault());
    }

    public function testDefaultIdConstantIsWebp(): void
    {
        $this->assertSame('webp', OutputFormat::DEFAULT_ID);
    }

    // --- coerce --------------------------------------------------------------

    public function testCoerceNullReturnsWebpDefault(): void
    {
        $this->assertSame('webp', OutputFormat::coerce(null)->id());
    }

    public function testCoerceStringIdReturnsMatchingFormat(): void
    {
        $this->assertSame('avif', OutputFormat::coerce('avif')->id());
    }

    public function testCoerceInstanceReturnsSameInstance(): void
    {
        $avif = OutputFormat::byId('avif');
        $this->assertSame($avif, OutputFormat::coerce($avif));
    }

    public function testCoerceUnknownStringThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        OutputFormat::coerce('heic');
    }

    // --- identity / immutability ---------------------------------------------

    public function testByIdReturnsTheSameSingletonInstance(): void
    {
        // The registry hands out the same instance for a given id, so identity
        // comparison is safe.
        $this->assertSame(OutputFormat::byId('webp'), OutputFormat::byId('webp'));
        $this->assertNotSame(OutputFormat::byId('webp'), OutputFormat::byId('avif'));
    }
}
