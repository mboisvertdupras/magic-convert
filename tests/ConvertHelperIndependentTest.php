<?php

namespace MagicConvert\Tests;

use MagicConvert\ConvertHelperIndependent;
use MagicConvert\OutputFormat;
use PHPUnit\Framework\TestCase;

class ConvertHelperIndependentTest extends TestCase
{
    public function testAppendExtensionWhenNotMingled()
    {
        $this->assertSame(
            '/var/www/uploads/logo.jpg.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'separate', 'set', true)
        );
    }

    public function testAppendExtensionWhenMingledButExtIsAppend()
    {
        $this->assertSame(
            '/var/www/uploads/logo.jpg.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'mingled', 'append', true)
        );
    }

    public function testAppendExtensionWhenMingledSetButNotInUploadFolder()
    {
        $this->assertSame(
            '/var/www/uploads/logo.jpg.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'mingled', 'set', false)
        );
    }

    public function testSetExtensionReplacesJpgWhenMingledSetInUpload()
    {
        $this->assertSame(
            '/var/www/uploads/logo.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'mingled', 'set', true)
        );
    }

    public function testSetExtensionReplacesJpegWhenMingledSetInUpload()
    {
        $this->assertSame(
            '/var/www/uploads/photo.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/photo.jpeg', 'mingled', 'set', true)
        );
    }

    public function testSetExtensionReplacesPngWhenMingledSetInUpload()
    {
        $this->assertSame(
            '/var/www/uploads/icon.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/icon.png', 'mingled', 'set', true)
        );
    }

    public function testSetExtensionIsCaseInsensitiveOnSourceExtension()
    {
        $this->assertSame(
            '/var/www/uploads/PHOTO.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/PHOTO.JPG', 'mingled', 'set', true)
        );
    }

    public function testSetExtensionAppendsForUnknownSourceExtension()
    {
        $this->assertSame(
            '/var/www/uploads/anim.gif.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/anim.gif', 'mingled', 'set', true)
        );
    }

    public function testAppendAvifExtensionWhenNotMingled()
    {
        $this->assertSame(
            '/var/www/uploads/logo.jpg.avif',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'separate', 'set', true, 'avif')
        );
    }

    public function testAppendAvifExtensionAcceptsOutputFormatInstance()
    {
        $this->assertSame(
            '/var/www/uploads/logo.jpg.avif',
            ConvertHelperIndependent::appendOrSetExtension(
                '/var/www/uploads/logo.jpg',
                'separate',
                'append',
                false,
                OutputFormat::byId('avif')
            )
        );
    }

    public function testSetAvifExtensionReplacesJpgWhenMingledSetInUpload()
    {
        $this->assertSame(
            '/var/www/uploads/logo.avif',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'mingled', 'set', true, 'avif')
        );
    }

    public function testSetAvifExtensionReplacesPngWhenMingledSetInUpload()
    {
        $this->assertSame(
            '/var/www/uploads/icon.avif',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/icon.png', 'mingled', 'set', true, 'avif')
        );
    }

    public function testExplicitWebpFormatMatchesDefaultBehaviour()
    {
        $default = ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'mingled', 'set', true);
        $explicit = ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/logo.jpg', 'mingled', 'set', true, 'webp');
        $this->assertSame($default, $explicit);
        $this->assertSame('/var/www/uploads/logo.webp', $explicit);
    }

    public function testGetDestinationMingledSetInsideUploadFolderSetsExtension()
    {
        $destination = ConvertHelperIndependent::getDestination(
            '/var/www/wp-content/uploads/2026/06/logo.jpg',
            'mingled',
            'set',
            '/var/www/wp-content/magic-convert',
            '/var/www/wp-content/uploads',
            false,
            null
        );

        $this->assertSame('/var/www/wp-content/uploads/2026/06/logo.webp', $destination);
    }

    public function testGetDestinationMingledAppendInsideUploadFolderAppendsExtension()
    {
        $destination = ConvertHelperIndependent::getDestination(
            '/var/www/wp-content/uploads/2026/06/logo.jpg',
            'mingled',
            'append',
            '/var/www/wp-content/magic-convert',
            '/var/www/wp-content/uploads',
            false,
            null
        );

        $this->assertSame('/var/www/wp-content/uploads/2026/06/logo.jpg.webp', $destination);
    }

    public function testGetDestinationMingledSetAvifInsideUploadFolderSetsAvifExtension()
    {
        $destination = ConvertHelperIndependent::getDestination(
            '/var/www/wp-content/uploads/2026/06/logo.jpg',
            'mingled',
            'set',
            '/var/www/wp-content/magic-convert',
            '/var/www/wp-content/uploads',
            false,
            null,
            'avif'
        );

        $this->assertSame('/var/www/wp-content/uploads/2026/06/logo.avif', $destination);
    }

    public function testGetDestinationMingledAppendAvifInsideUploadFolderAppendsAvifExtension()
    {
        $destination = ConvertHelperIndependent::getDestination(
            '/var/www/wp-content/uploads/2026/06/logo.jpg',
            'mingled',
            'append',
            '/var/www/wp-content/magic-convert',
            '/var/www/wp-content/uploads',
            false,
            null,
            OutputFormat::byId('avif')
        );

        $this->assertSame('/var/www/wp-content/uploads/2026/06/logo.jpg.avif', $destination);
    }
}
