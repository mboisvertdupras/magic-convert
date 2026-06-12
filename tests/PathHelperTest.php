<?php

namespace MagicConvert\Tests;

use MagicConvert\PathHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MagicConvert\PathHelper.
 *
 * Only the pure string/path-manipulation methods are exercised here — the ones
 * that do not touch the filesystem, $_SERVER or realpath(). Those are the parts
 * that genuinely run standalone without WordPress.
 */
class PathHelperTest extends TestCase
{
    public function testCanonicalizeResolvesSingleDotSegments()
    {
        $this->assertSame('/var/www/images', PathHelper::canonicalize('/var/www/./images'));
        $this->assertSame('/var/www', PathHelper::canonicalize('/var/./www/.'));
    }

    public function testCanonicalizeResolvesParentSegments()
    {
        $this->assertSame('/var/images', PathHelper::canonicalize('/var/www/../images'));
        $this->assertSame('/a/d', PathHelper::canonicalize('/a/b/c/../../d'));
    }

    public function testCanonicalizeHandlesDotDotDotSequence()
    {
        // The class docblock specifically calls out that it was fixed to handle './../'
        $this->assertSame('/images', PathHelper::canonicalize('/var/./../images'));
    }

    public function testFixDoubleSlashCollapsesRepeatedSlashes()
    {
        $this->assertSame('/var/www/', PathHelper::fixDoubleSlash('/var//www/'));
        $this->assertSame('/var/www/', PathHelper::fixDoubleSlash('/var////www/'));
        $this->assertSame('/a/b/c', PathHelper::fixDoubleSlash('/a//b///c'));
    }

    public function testFixDoubleSlashLeavesCleanPathUntouched()
    {
        $this->assertSame('/var/www/img.jpg', PathHelper::fixDoubleSlash('/var/www/img.jpg'));
    }

    public function testUntrailSlashRemovesTrailingSlashes()
    {
        $this->assertSame('/var/www', PathHelper::untrailSlash('/var/www/'));
        $this->assertSame('/var/www', PathHelper::untrailSlash('/var/www///'));
        $this->assertSame('/var/www', PathHelper::untrailSlash('/var/www'));
    }

    public function testBackslashesToForwardSlashes()
    {
        $this->assertSame('C:/Users/test', PathHelper::backslashesToForwardSlashes('C:\\Users\\test'));
        $this->assertSame('/var/www', PathHelper::backslashesToForwardSlashes('/var/www'));
    }

    public function testBasenameReturnsLastComponent()
    {
        $this->assertSame('logo.jpg', PathHelper::basename('/var/www/uploads/logo.jpg'));
        $this->assertSame('uploads', PathHelper::basename('/var/www/uploads'));
        $this->assertSame('logo.jpg', PathHelper::basename('logo.jpg'));
    }

    public function testDirnameReturnsParentDir()
    {
        $this->assertSame('/var/www/uploads', PathHelper::dirname('/var/www/uploads/logo.jpg'));
        $this->assertSame('/var/www', PathHelper::dirname('/var/www/uploads'));
    }

    public function testIsAbsPathDetectsLeadingSlash()
    {
        $this->assertTrue(PathHelper::isAbsPath('/var/www'));
        $this->assertFalse(PathHelper::isAbsPath('var/www'));
        $this->assertFalse(PathHelper::isAbsPath('./relative'));
        $this->assertFalse(PathHelper::isAbsPath(''));
    }

    public function testRelPathToAbsPathJoinsAndCanonicalizes()
    {
        $this->assertSame('/var/www/images', PathHelper::relPathToAbsPath('images', '/var/www'));
        // Double slashes and dot segments get cleaned up
        $this->assertSame('/var/www/images', PathHelper::relPathToAbsPath('./images', '/var/www/'));
        $this->assertSame('/var/images', PathHelper::relPathToAbsPath('../images', '/var/www'));
    }

    public function testPathToAbsPathReturnsAbsoluteInputUnchanged()
    {
        $this->assertSame('/already/absolute', PathHelper::pathToAbsPath('/already/absolute', '/var/www'));
    }

    public function testPathToAbsPathResolvesRelativeInput()
    {
        $this->assertSame('/var/www/relative', PathHelper::pathToAbsPath('relative', '/var/www'));
    }

    public function testGetRelDirForChildPath()
    {
        $this->assertSame('images', PathHelper::getRelDir('/var/www', '/var/www/images'));
    }

    public function testGetRelDirForSiblingPath()
    {
        $this->assertSame('../ddd', PathHelper::getRelDir('/var/www', '/var/ddd'));
    }

    public function testGetRelDirForSamePathReturnsDot()
    {
        $this->assertSame('.', PathHelper::getRelDir('/var/www', '/var/www'));
    }

    public function testGetRelDirIgnoresTrailingSlashes()
    {
        $this->assertSame('images', PathHelper::getRelDir('/var/www/', '/var/www/images/'));
    }

    public function testGetRelDirForDeeplyNestedTarget()
    {
        $this->assertSame('a/b/c', PathHelper::getRelDir('/root', '/root/a/b/c'));
    }
}
