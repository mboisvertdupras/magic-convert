<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;

class AvifSanitizeClampTest extends TestCase
{
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
        $this->assertSame(0, $this->clampAvifQuality('abc'));
        $this->assertSame(40, $this->clampAvifQuality('40x'));
    }

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
