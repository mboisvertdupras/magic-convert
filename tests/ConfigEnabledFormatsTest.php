<?php

namespace MagicConvert\Tests;

use MagicConvert\Config;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Config::enabledFormatIds() — the single source of truth for "which output formats
 * does a bulk/REST/CLI run produce?" (Phase 2.5).
 *
 * Pure & WordPress-independent (reads the passed config array + the OutputFormat registry).
 */
class ConfigEnabledFormatsTest extends TestCase
{
    public function testDefaultIsWebpOnlyFastPath(): void
    {
        // The default install: webp on, avif off => exactly ['webp'] (the fast path).
        $config = ['formats' => ['webp' => ['enabled' => true], 'avif' => ['enabled' => false]]];
        $this->assertSame(['webp'], Config::enabledFormatIds($config));
    }

    public function testAvifEnabledAddsAvifInRegistryOrder(): void
    {
        $config = ['formats' => ['webp' => ['enabled' => true], 'avif' => ['enabled' => true]]];
        $this->assertSame(['webp', 'avif'], Config::enabledFormatIds($config));
    }

    public function testWebpIsAlwaysEnabledEvenIfFlagIsFalse(): void
    {
        // WebP is the baseline output and cannot be turned off.
        $config = ['formats' => ['webp' => ['enabled' => false], 'avif' => ['enabled' => false]]];
        $this->assertSame(['webp'], Config::enabledFormatIds($config));
    }

    public function testMissingFormatsSectionFallsBackToWebp(): void
    {
        $this->assertSame(['webp'], Config::enabledFormatIds([]));
        $this->assertSame(['webp'], Config::enabledFormatIds(['formats' => 'not-an-array']));
    }

    public function testAvifEnabledOnlyWhenStrictlyTrue(): void
    {
        // A truthy-but-not-true value (e.g. "1") must NOT enable avif — explicit === true only.
        $config = ['formats' => ['webp' => ['enabled' => true], 'avif' => ['enabled' => '1']]];
        $this->assertSame(['webp'], Config::enabledFormatIds($config));
    }
}
