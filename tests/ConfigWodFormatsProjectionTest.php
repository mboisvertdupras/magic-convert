<?php

namespace MagicConvert\Tests;

use MagicConvert\Config;
use PHPUnit\Framework\TestCase;

class ConfigWodFormatsProjectionTest extends TestCase
{
    public function testAvifEnabledWithCustomSettingsProjectsExactBlock(): void
    {
        $config = ['formats' => [
            'webp' => ['enabled' => true],
            'avif' => [
                'enabled' => true,
                'quality' => 45,
                'speed' => 3,
                'converters' => [
                    ['converter' => 'vips'],
                    ['converter' => 'gd'],
                ],
            ],
        ]];

        $expected = [
            'webp' => [
                'enabled' => true,
            ],
            'avif' => [
                'enabled' => true,
                'quality' => 45,
                'speed' => 3,
                'converters' => [
                    ['converter' => 'vips'],
                    ['converter' => 'gd'],
                ],
            ],
        ];

        $this->assertSame($expected, Config::buildWodFormatsProjection($config));
    }

    public function testAvifAbsentProjectsDefaultsBlock(): void
    {
        $expected = [
            'webp' => [
                'enabled' => true,
            ],
            'avif' => [
                'enabled' => false,
                'quality' => 30,
                'speed' => 6,
                'converters' => [
                    ['converter' => 'imagick'],
                    ['converter' => 'vips'],
                    ['converter' => 'gd'],
                    ['converter' => 'magick-binary'],
                    ['converter' => 'avifenc'],
                    ['converter' => 'cavif'],
                ],
            ],
        ];

        $this->assertSame($expected, Config::buildWodFormatsProjection([]));
    }
}
