<?php

namespace MagicConvert\Tests;

use MagicConvert\ConvertHelperIndependent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WordPress-independent extension/destination logic in
 * MagicConvert\ConvertHelperIndependent.
 *
 * Only the genuinely standalone bits are covered:
 *
 *  - appendOrSetExtension(): pure string logic, no filesystem, no $_SERVER.
 *  - getDestination() restricted to the "mingled + source inside upload folder"
 *    branch, which is also pure string logic (it does not hit realpath, image
 *    roots, or document root). The other getDestination() branches and
 *    getLogFilename()/findSource()/convert() are intentionally NOT tested here
 *    because they depend on realpath(), $_SERVER['DOCUMENT_ROOT'] and/or an
 *    ImageRoots object — see the task notes.
 */
class ConvertHelperIndependentTest extends TestCase
{
    // --- appendOrSetExtension ------------------------------------------------

    public function testAppendExtensionWhenNotMingled()
    {
        // Not mingled -> always append, regardless of ext mode / upload flag
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
        // .gif is not jpe?g|png, so the regex does not strip it -> .webp appended
        $this->assertSame(
            '/var/www/uploads/anim.gif.webp',
            ConvertHelperIndependent::appendOrSetExtension('/var/www/uploads/anim.gif', 'mingled', 'set', true)
        );
    }

    // --- getDestination (pure mingled-in-upload branch) ----------------------

    public function testGetDestinationMingledSetInsideUploadFolderSetsExtension()
    {
        // Source is inside the upload dir, folder=mingled, ext=set
        // -> storeMingledOrNot() is true and appendOrSetExtension() SETs the ext.
        $destination = ConvertHelperIndependent::getDestination(
            '/var/www/wp-content/uploads/2026/06/logo.jpg', // source
            'mingled',                                       // destinationFolder
            'set',                                           // destinationExt
            '/var/www/wp-content/magic-convert',             // webExpressContentDirAbs (unused in this branch)
            '/var/www/wp-content/uploads',                   // uploadDirAbs
            false,                                           // useDocRootForStructuringCacheDir
            null                                             // imageRoots (unused in this branch)
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
}
