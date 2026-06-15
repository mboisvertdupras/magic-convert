<?php

namespace MagicConvert\Tests;

use MagicConvert\Config;
use PHPUnit\Framework\TestCase;

class ConfigMigrationTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private function v1Fixture(): array
    {
        return [
            'operation-mode' => 'varied-image-responses',
            'image-types' => 3,
            'quality-auto' => false,
            'quality-specific' => 70,
            'max-quality' => 80,
            'converters' => [],
            'scope' => ['themes', 'uploads'],
        ];
    }

    public function testV1FixtureGainsConfigVersion(): void
    {
        $config = $this->v1Fixture();
        $this->assertArrayNotHasKey('config-version', $config);

        $migrated = Config::migrateToV2($config);

        $this->assertSame(2, $migrated['config-version']);
        $this->assertSame(Config::CONFIG_VERSION, $migrated['config-version']);
    }

    public function testV1FixtureGainsFormatsSection(): void
    {
        $migrated = Config::migrateToV2($this->v1Fixture());

        $this->assertArrayHasKey('formats', $migrated);
        $this->assertArrayHasKey('webp', $migrated['formats']);
        $this->assertArrayHasKey('avif', $migrated['formats']);
    }

    public function testMigratedFormatsMatchDefaults(): void
    {
        $migrated = Config::migrateToV2($this->v1Fixture());

        $this->assertTrue($migrated['formats']['webp']['enabled']);
        $this->assertFalse($migrated['formats']['avif']['enabled']);
        $this->assertSame(30, $migrated['formats']['avif']['quality']);
        $this->assertSame(6, $migrated['formats']['avif']['speed']);

        $this->assertSame(Config::getDefaultFormats(), $migrated['formats']);
    }

    public function testMigrationPreservesExistingTopLevelKeys(): void
    {
        $config = $this->v1Fixture();
        $migrated = Config::migrateToV2($config);

        foreach ($config as $key => $value) {
            $this->assertArrayHasKey($key, $migrated);
            $this->assertSame($value, $migrated[$key]);
        }
    }

    public function testCompleteV2ConfigPassesThroughUntouched(): void
    {
        $v2 = [
            'config-version' => 2,
            'formats' => [
                'webp' => ['enabled' => true],
                'avif' => [
                    'enabled' => true,
                    'quality' => 45,
                    'speed' => 3,
                    'converters' => Config::getDefaultFormats()['avif']['converters'],
                ],
            ],
            'operation-mode' => 'cdn-friendly',
        ];

        $this->assertSame($v2, Config::migrateToV2($v2));
    }

    public function testV2ConfigBackfillsNewlyIntroducedPerFormatKeys(): void
    {
        $v2 = [
            'config-version' => 2,
            'formats' => [
                'webp' => ['enabled' => true],
                'avif' => ['enabled' => true, 'quality' => 45, 'speed' => 3],
            ],
            'operation-mode' => 'cdn-friendly',
        ];

        $migrated = Config::migrateToV2($v2);

        $this->assertSame(2, $migrated['config-version']);
        $this->assertSame('cdn-friendly', $migrated['operation-mode']);
        $this->assertTrue($migrated['formats']['avif']['enabled']);
        $this->assertSame(45, $migrated['formats']['avif']['quality']);
        $this->assertSame(3, $migrated['formats']['avif']['speed']);
        $this->assertSame(
            Config::getDefaultFormats()['avif']['converters'],
            $migrated['formats']['avif']['converters']
        );
    }

    public function testV2MigrationDoesNotClobberUserAvifSettings(): void
    {
        $v2 = [
            'config-version' => 2,
            'formats' => [
                'webp' => ['enabled' => true],
                'avif' => ['enabled' => true, 'quality' => 55, 'speed' => 2],
            ],
        ];

        $migrated = Config::migrateToV2($v2);

        $this->assertTrue($migrated['formats']['avif']['enabled']);
        $this->assertSame(55, $migrated['formats']['avif']['quality']);
        $this->assertSame(2, $migrated['formats']['avif']['speed']);
    }

    public function testV2MigrationPreservesUserAvifConverterList(): void
    {
        $custom = [
            ['converter' => 'avifenc'],
            ['converter' => 'vips', 'deactivated' => true],
            ['converter' => 'imagick'],
            ['converter' => 'gd'],
            ['converter' => 'magick-binary'],
            ['converter' => 'cavif'],
        ];
        $v2 = [
            'config-version' => 2,
            'formats' => [
                'webp' => ['enabled' => true],
                'avif' => ['enabled' => true, 'quality' => 30, 'speed' => 6, 'converters' => $custom],
            ],
        ];

        $migrated = Config::migrateToV2($v2);

        $this->assertSame($custom, $migrated['formats']['avif']['converters']);
    }

    public function testMigrationIsIdempotent(): void
    {
        $once = Config::migrateToV2($this->v1Fixture());
        $twice = Config::migrateToV2($once);

        $this->assertSame($once, $twice);
    }

    public function testMigrationRunThriceEqualsOnce(): void
    {
        $once = Config::migrateToV2($this->v1Fixture());
        $thrice = Config::migrateToV2(Config::migrateToV2(Config::migrateToV2($this->v1Fixture())));

        $this->assertSame($once, $thrice);
    }

    public function testPartialFormatsSectionIsFilledFromDefaults(): void
    {
        $config = [
            'config-version' => 2,
            'formats' => [
                'avif' => ['enabled' => true, 'quality' => 25],
            ],
        ];

        $migrated = Config::migrateToV2($config);

        $this->assertTrue($migrated['formats']['avif']['enabled']);
        $this->assertSame(25, $migrated['formats']['avif']['quality']);
        $this->assertSame(6, $migrated['formats']['avif']['speed']);
        $this->assertTrue($migrated['formats']['webp']['enabled']);
    }

    public function testCorruptFormatsSectionIsReplacedWithDefaults(): void
    {
        $config = [
            'operation-mode' => 'varied-image-responses',
            'formats' => 'not-an-array',
        ];

        $migrated = Config::migrateToV2($config);

        $this->assertSame(Config::getDefaultFormats(), $migrated['formats']);
        $this->assertSame(2, $migrated['config-version']);
    }

    public function testGetDefaultFormatsShape(): void
    {
        $formats = Config::getDefaultFormats();

        $this->assertSame(['webp', 'avif'], array_keys($formats));

        $this->assertSame(['enabled' => true], $formats['webp']);

        $this->assertSame(
            ['enabled' => false, 'quality' => 30, 'speed' => 6],
            array_diff_key($formats['avif'], ['converters' => true])
        );

        $expectedConverters = array_map(
            static fn ($id) => ['converter' => $id],
            \MagicConvert\Avif\AvifStack::defaultConverterIds()
        );
        $this->assertSame($expectedConverters, $formats['avif']['converters']);
        $this->assertSame(
            ['imagick', 'vips', 'gd', 'magick-binary', 'avifenc', 'cavif'],
            array_column($formats['avif']['converters'], 'converter')
        );
    }

    public function testConfigVersionConstantIsTwo(): void
    {
        $this->assertSame(2, Config::CONFIG_VERSION);
    }
}
