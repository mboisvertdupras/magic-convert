<?php

namespace MagicConvert\Tests;

use MagicConvert\PhpCliLocator;
use PHPUnit\Framework\TestCase;

class PhpCliLocatorTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('MAGIC_CONVERT_PHP_CLI');
        PhpCliLocator::reset();
    }

    public function testCandidateNamesPreferVersionedThenFallBackToPlainPhp(): void
    {
        $names = PhpCliLocator::candidateNames();
        $this->assertSame('php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $names[0]);
        $this->assertContains('php' . PHP_MAJOR_VERSION, $names);
        $this->assertSame('php', end($names));
    }

    public function testStandardBinDirsCoverCommonLocationsWithoutHardcodingADevBox(): void
    {
        $dirs = PhpCliLocator::standardBinDirs();
        $this->assertContains('/usr/local/bin', $dirs);
        $this->assertContains('/usr/bin', $dirs);
        $this->assertContains('/bin', $dirs);
        $this->assertNotContains('/opt/homebrew/bin', $dirs);
    }

    public function testCandidatePathsIncludePhpBindirAndAreDeduped(): void
    {
        $paths = PhpCliLocator::candidatePaths();
        $this->assertContains(rtrim(PHP_BINDIR, '/') . '/php', $paths);
        $this->assertContains('/usr/local/bin/php', $paths);
        $this->assertSame(array_values(array_unique($paths)), $paths);
    }

    public function testOverridePathReadsEnvAndIsNullWhenUnset(): void
    {
        putenv('MAGIC_CONVERT_PHP_CLI');
        $this->assertNull(PhpCliLocator::overridePath());

        putenv('MAGIC_CONVERT_PHP_CLI=/custom/php-cli');
        $this->assertSame('/custom/php-cli', PhpCliLocator::overridePath());
    }
}
