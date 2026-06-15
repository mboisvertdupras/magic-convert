<?php

namespace MagicConvert\Tests;

use MagicConvert\OutputFormat;
use PHPUnit\Framework\TestCase;

class OutputFormatTest extends TestCase
{
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
        $this->assertCount(count(OutputFormat::ids()), $all);
    }

    public function testWebpIsTheFirstRegisteredFormat(): void
    {
        $ids = OutputFormat::ids();
        $this->assertSame('webp', $ids[0]);
    }

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

    public function testByIdReturnsTheSameSingletonInstance(): void
    {
        $this->assertSame(OutputFormat::byId('webp'), OutputFormat::byId('webp'));
        $this->assertNotSame(OutputFormat::byId('webp'), OutputFormat::byId('avif'));
    }
}
