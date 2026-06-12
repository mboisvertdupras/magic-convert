<?php

namespace MagicConvert\Tests;

use MagicConvert\ConvertHelperIndependent;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure static helpers introduced for Phase 1.1 concurrency
 * hardening in MagicConvert\ConvertHelperIndependent:
 *
 *  - lockPathForDestination(): '<destination>.lock'
 *  - tempDestinationFor():     '<destination-without-.webp>.<pid>.tmp.webp'
 *                              (MUST still end in .webp to satisfy the plugin's
 *                              own '#\.webp$#' check and the library validator)
 *  - isDestinationFresh():     destination mtime >= source mtime
 *
 * These are pure logic (no filesystem, no $_SERVER) so they unit-test directly.
 */
class ConvertHelperConcurrencyTest extends TestCase
{
    // --- lockPathForDestination ---------------------------------------------

    public function testLockPathIsDestinationPlusLock(): void
    {
        $this->assertSame(
            '/cache/uploads/2026/06/logo.jpg.webp.lock',
            ConvertHelperIndependent::lockPathForDestination('/cache/uploads/2026/06/logo.jpg.webp')
        );
    }

    // --- tempDestinationFor --------------------------------------------------

    public function testTempDestinationStillEndsInWebp(): void
    {
        $temp = ConvertHelperIndependent::tempDestinationFor('/cache/logo.jpg.webp', 4242);
        $this->assertMatchesRegularExpression(
            '#\.webp$#',
            $temp,
            'temp destination must end in .webp to satisfy both sanity checks'
        );
    }

    public function testTempDestinationContainsPidToken(): void
    {
        $temp = ConvertHelperIndependent::tempDestinationFor('/cache/logo.jpg.webp', 4242);
        $this->assertSame('/cache/logo.jpg.4242.tmp.webp', $temp);
    }

    public function testTempDestinationStripsTrailingWebpCaseInsensitively(): void
    {
        // A destination ending in ".WEBP" should still produce a single trailing
        // ".webp" on the temp (no doubled extension).
        $temp = ConvertHelperIndependent::tempDestinationFor('/cache/logo.jpg.WEBP', 7);
        $this->assertSame('/cache/logo.jpg.7.tmp.webp', $temp);
    }

    public function testTempDestinationIsDistinctFromFinalDestination(): void
    {
        $dest = '/cache/logo.jpg.webp';
        $this->assertNotSame(
            $dest,
            ConvertHelperIndependent::tempDestinationFor($dest, 99),
            'temp must differ from final destination so rename is a real move'
        );
    }

    public function testTempDestinationDefaultsToCurrentPid(): void
    {
        $dest = '/cache/logo.jpg.webp';
        $temp = ConvertHelperIndependent::tempDestinationFor($dest);
        $this->assertSame('/cache/logo.jpg.' . getmypid() . '.tmp.webp', $temp);
    }

    // --- tempDestinationFor: AVIF format (Phase 2.1) ------------------------

    public function testTempDestinationForAvifEndsInAvif(): void
    {
        $temp = ConvertHelperIndependent::tempDestinationFor('/cache/logo.jpg.avif', 4242, 'avif');
        $this->assertSame('/cache/logo.jpg.4242.tmp.avif', $temp);
    }

    public function testTempDestinationForAvifStripsTrailingAvifCaseInsensitively(): void
    {
        $temp = ConvertHelperIndependent::tempDestinationFor('/cache/logo.jpg.AVIF', 7, 'avif');
        $this->assertSame('/cache/logo.jpg.7.tmp.avif', $temp);
    }

    public function testExplicitWebpFormatMatchesDefaultTempDestination(): void
    {
        $dest = '/cache/logo.jpg.webp';
        $this->assertSame(
            ConvertHelperIndependent::tempDestinationFor($dest, 99),
            ConvertHelperIndependent::tempDestinationFor($dest, 99, 'webp')
        );
    }

    // --- isDestinationFresh --------------------------------------------------

    public function testDestinationNewerThanSourceIsFresh(): void
    {
        $this->assertTrue(ConvertHelperIndependent::isDestinationFresh(2000, 1000));
    }

    public function testDestinationEqualMtimeIsFresh(): void
    {
        // >= so an exactly-equal mtime counts as fresh.
        $this->assertTrue(ConvertHelperIndependent::isDestinationFresh(1000, 1000));
    }

    public function testDestinationOlderThanSourceIsNotFresh(): void
    {
        $this->assertFalse(ConvertHelperIndependent::isDestinationFresh(500, 1000));
    }

    public function testMissingDestinationMtimeIsNotFresh(): void
    {
        // filemtime() returns false for a missing file.
        $this->assertFalse(ConvertHelperIndependent::isDestinationFresh(false, 1000));
    }

    public function testMissingSourceMtimeIsNotFresh(): void
    {
        $this->assertFalse(ConvertHelperIndependent::isDestinationFresh(1000, false));
    }
}
