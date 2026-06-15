<?php

namespace MagicConvert\Tests;

use MagicConvert\Config;
use PHPUnit\Framework\TestCase;

class ConfigEnabledFormatsTest extends TestCase
{
    public function testDefaultIsWebpOnlyFastPath(): void
    {
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
        $config = ['formats' => ['webp' => ['enabled' => true], 'avif' => ['enabled' => '1']]];
        $this->assertSame(['webp'], Config::enabledFormatIds($config));
    }
}
