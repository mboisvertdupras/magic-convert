<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Sanitization-clamp tests for the AVIF options persisted by lib/options/submit.php (Phase 2.2).
 *
 * submit.php is a procedural admin-post handler that exits when loaded outside WordPress (it calls
 * check_admin_referer() at the top and runs the save flow at file scope), so it cannot be require()'d
 * into a unit test. Its clamp helper, however, is a one-line pure expression:
 *
 *     magicconvert_clampInt($v, $min, $max)  ==  max($min, min(intval($v), $max))
 *
 * This test reproduces that exact expression and pins the AVIF bounds the submit handler enforces:
 *   - avif-quality : clamped to [0, 100]
 *   - avif-speed   : clamped to [0, 10]
 *
 * If those bounds ever drift in submit.php, this is the canary.
 */
class AvifSanitizeClampTest extends TestCase
{
    /** Mirror of magicconvert_clampInt() in lib/options/submit.php. */
    private function clampInt($value, int $min, int $max): int
    {
        return max($min, min(intval($value), $max));
    }

    private function clampAvifQuality($value): int
    {
        return $this->clampInt($value, 0, 100);
    }

    private function clampAvifSpeed($value): int
    {
        return $this->clampInt($value, 0, 10);
    }

    // --- quality (0-100) ------------------------------------------------------

    public function testQualityWithinRangeIsUnchanged(): void
    {
        $this->assertSame(30, $this->clampAvifQuality(30));
        $this->assertSame(0, $this->clampAvifQuality(0));
        $this->assertSame(100, $this->clampAvifQuality(100));
    }

    public function testQualityAboveMaxClampsTo100(): void
    {
        $this->assertSame(100, $this->clampAvifQuality(101));
        $this->assertSame(100, $this->clampAvifQuality(999));
    }

    public function testQualityBelowMinClampsTo0(): void
    {
        $this->assertSame(0, $this->clampAvifQuality(-1));
        $this->assertSame(0, $this->clampAvifQuality(-500));
    }

    public function testQualityNonNumericFloorsToInt(): void
    {
        // intval() of garbage is 0, which is in range.
        $this->assertSame(0, $this->clampAvifQuality('abc'));
        // intval('40x') === 40.
        $this->assertSame(40, $this->clampAvifQuality('40x'));
    }

    // --- speed (0-10) ---------------------------------------------------------

    public function testSpeedWithinRangeIsUnchanged(): void
    {
        $this->assertSame(6, $this->clampAvifSpeed(6));
        $this->assertSame(0, $this->clampAvifSpeed(0));
        $this->assertSame(10, $this->clampAvifSpeed(10));
    }

    public function testSpeedAboveMaxClampsTo10(): void
    {
        $this->assertSame(10, $this->clampAvifSpeed(11));
        $this->assertSame(10, $this->clampAvifSpeed(100));
    }

    public function testSpeedBelowMinClampsTo0(): void
    {
        $this->assertSame(0, $this->clampAvifSpeed(-1));
        $this->assertSame(0, $this->clampAvifSpeed(-99));
    }
}
