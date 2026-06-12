<?php

namespace MagicConvert\Tests;

use MagicConvert\SanityCheck;
use MagicConvert\SanityException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MagicConvert\SanityCheck.
 *
 * SanityCheck is the security boundary inherited from upstream WebP Express:
 * it rejects directory traversal, NUL bytes, control characters and stream
 * wrappers in untrusted paths. These checks are pure PHP (no WordPress).
 *
 * On rejection, SanityCheck::fail() calls error_log() and then throws a
 * SanityException. error_log() output goes to the PHP error log, not to test
 * stdout, so it does not trip PHPUnit's strict-output mode.
 *
 * The accept-case methods return the (sanitized) input on success, which is
 * what we assert against.
 */
class SanityCheckTest extends TestCase
{
    // --- mustBeString --------------------------------------------------------

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
        // Any non-string scalar must be rejected by the type guard.
        $this->expectException(SanityException::class);
        SanityCheck::mustBeString(true);
    }

    public function testMustBeStringRejectsFloat()
    {
        $this->expectException(SanityException::class);
        SanityCheck::mustBeString(1.5);
    }

    // --- noNUL ---------------------------------------------------------------

    public function testNoNulAcceptsCleanString()
    {
        $this->assertSame('/var/www/logo.jpg', SanityCheck::noNUL('/var/www/logo.jpg'));
    }

    public function testNoNulRejectsEmbeddedNulByte()
    {
        $this->expectException(SanityException::class);
        SanityCheck::noNUL("/var/www/logo.jpg\0.php");
    }

    // --- noControlChars ------------------------------------------------------

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

    // --- noDirectoryTraversal ------------------------------------------------

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

    // --- noStreamWrappers ----------------------------------------------------

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

    // --- notEmpty ------------------------------------------------------------

    public function testNotEmptyAcceptsNonEmptyString()
    {
        $this->assertSame('x', SanityCheck::notEmpty('x'));
    }

    public function testNotEmptyRejectsEmptyString()
    {
        $this->expectException(SanityException::class);
        SanityCheck::notEmpty('');
    }

    // --- path (composite) ----------------------------------------------------

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

    // --- absPath -------------------------------------------------------------

    public function testAbsPathAcceptsLeadingSlash()
    {
        $this->assertSame('/var/www/logo.jpg', SanityCheck::absPath('/var/www/logo.jpg'));
    }

    public function testAbsPathRejectsRelativePath()
    {
        $this->expectException(SanityException::class);
        SanityCheck::absPath('relative/path.jpg');
    }

    // --- pregMatch -----------------------------------------------------------

    public function testPregMatchAcceptsMatchingInput()
    {
        $this->assertSame('logo.webp', SanityCheck::pregMatch('#\.webp$#', 'logo.webp'));
    }

    public function testPregMatchRejectsNonMatchingInput()
    {
        $this->expectException(SanityException::class);
        SanityCheck::pregMatch('#\.webp$#', 'logo.jpg');
    }

    // --- isJSONArray / isJSONObject -----------------------------------------

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
