<?php

namespace MagicConvert\Tests;

use MagicConvert\SanityCheck;
use MagicConvert\SanityException;
use PHPUnit\Framework\TestCase;

class SanityCheckTest extends TestCase
{
    public function testMustBeStringAcceptsString()
    {
        $this->assertSame('hello', SanityCheck::mustBeString('hello'));
        $this->assertSame('', SanityCheck::mustBeString(''));
    }

    public function testMustBeStringRejectsInteger()
    {
        $this->expectException(SanityException::class);
        SanityCheck::mustBeString(123);
    }

    public function testMustBeStringRejectsBoolean()
    {
        $this->expectException(SanityException::class);
        SanityCheck::mustBeString(true);
    }

    public function testMustBeStringRejectsFloat()
    {
        $this->expectException(SanityException::class);
        SanityCheck::mustBeString(1.5);
    }

    public function testNoNulAcceptsCleanString()
    {
        $this->assertSame('/var/www/logo.jpg', SanityCheck::noNUL('/var/www/logo.jpg'));
    }

    public function testNoNulRejectsEmbeddedNulByte()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noNUL("/var/www/logo.jpg\0.php");
    }

    public function testNoControlCharsAcceptsPrintableString()
    {
        $this->assertSame('/var/www/image-1.jpg', SanityCheck::noControlChars('/var/www/image-1.jpg'));
    }

    public function testNoControlCharsRejectsNewline()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noControlChars("path/to\nfile");
    }

    public function testNoControlCharsRejectsTab()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noControlChars("path/to\tfile");
    }

    public function testNoControlCharsRejectsCarriageReturn()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noControlChars("path/to\rfile");
    }

    public function testNoDirectoryTraversalAcceptsSafePath()
    {
        $this->assertSame('/var/www/uploads/logo.jpg', SanityCheck::noDirectoryTraversal('/var/www/uploads/logo.jpg'));
    }

    public function testNoDirectoryTraversalRejectsDotDotSlash()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noDirectoryTraversal('/var/www/../../../etc/passwd');
    }

    public function testNoDirectoryTraversalRejectsLeadingDotDotSlash()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noDirectoryTraversal('../secret');
    }

    public function testNoStreamWrappersAcceptsPlainPath()
    {
        $this->assertSame('/var/www/logo.jpg', SanityCheck::noStreamWrappers('/var/www/logo.jpg'));
    }

    public function testNoStreamWrappersRejectsPharWrapper()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noStreamWrappers('phar://malicious.phar/payload');
    }

    public function testNoStreamWrappersRejectsPhpWrapper()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noStreamWrappers('php://filter/resource=secret');
    }

    public function testNotEmptyAcceptsNonEmptyString()
    {
        $this->assertSame('x', SanityCheck::notEmpty('x'));
    }

    public function testNotEmptyRejectsEmptyString()
    {
        $this->expectException(SanityException::class);
        SanityCheck::notEmpty('');
    }

    public function testPathAcceptsCleanAbsolutePath()
    {
        $this->assertSame('/var/www/uploads/photo.png', SanityCheck::path('/var/www/uploads/photo.png'));
    }

    public function testPathRejectsTraversal()
    {
        $this->expectException(SanityException::class);
        SanityCheck::path('/var/www/../../etc/passwd');
    }

    public function testPathRejectsStreamWrapper()
    {
        $this->expectException(SanityException::class);
        SanityCheck::path('phar://x/y');
    }

    public function testAbsPathAcceptsLeadingSlash()
    {
        $this->assertSame('/var/www/logo.jpg', SanityCheck::absPath('/var/www/logo.jpg'));
    }

    public function testAbsPathRejectsRelativePath()
    {
        $this->expectException(SanityException::class);
        SanityCheck::absPath('relative/path.jpg');
    }

    public function testPregMatchAcceptsMatchingInput()
    {
        $this->assertSame('logo.webp', SanityCheck::pregMatch('#\.webp$#', 'logo.webp'));
    }

    public function testPregMatchRejectsNonMatchingInput()
    {
        $this->expectException(SanityException::class);
        SanityCheck::pregMatch('#\.webp$#', 'logo.jpg');
    }

    public function testIsJsonArrayAcceptsArray()
    {
        $this->assertSame('[1,2,3]', SanityCheck::isJSONArray('[1,2,3]'));
    }

    public function testIsJsonArrayRejectsObject()
    {
        $this->expectException(SanityException::class);
        SanityCheck::isJSONArray('{"a":1}');
    }

    public function testIsJsonObjectAcceptsObject()
    {
        $this->assertSame('{"a":1}', SanityCheck::isJSONObject('{"a":1}'));
    }

    public function testIsJsonObjectRejectsArray()
    {
        $this->expectException(SanityException::class);
        SanityCheck::isJSONObject('[1,2,3]');
    }
}
