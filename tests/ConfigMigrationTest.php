<?php

namespace MagicConvert\Tests;

use MagicConvert\Config;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the config schema v1 -> v2 migration (Phase 2.2).
 *
 * Exercises the pure, WordPress-independent pieces of MagicConvert\Config:
 *   - Config::migrateToV2()       structural lift from v1 to v2
 *   - Config::getDefaultFormats() the per-format defaults
 *   - Config::CONFIG_VERSION      the schema version stamp
 *
 * These methods touch no filesystem, no WordPress, no database, so they run standalone.
 */
class ConfigMigrationTest extends TestCase
{
    /**
     * A minimal v1-shaped config: how upstream WebP Express (and Magic Convert pre-2.2) wrote
     * config.json — no 'config-version', no 'formats' section.
     *
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

    // --- v1 -> v2 lift --------------------------------------------------------

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

        // WebP enabled, AVIF disabled — the zero-config, byte-for-byte-equivalent default.
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

        // Everything that was there before is still there, unchanged.
        foreach ($config as $key => $value) {
            $this->assertArrayHasKey($key, $migrated);
            $this->assertSame($value, $migrated[$key]);
        }
    }

    // --- v2 passthrough -------------------------------------------------------

    public function testCompleteV2ConfigPassesThroughUntouched(): void
    {
        // A FULLY-formed v2 config (already carrying every per-format key, including the AVIF
        // converter stack) must pass through migrateToV2() byte-for-byte.
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
        // A v2 config saved BEFORE formats.avif.converters existed lacks that key. Migration must
        // backfill it from defaults without disturbing the user's enable/quality/speed choices.
        $v2 = [
            'config-version' => 2,
            'formats' => [
                'webp' => ['enabled' => true],
                'avif' => ['enabled' => true, 'quality' => 45, 'speed' => 3],
            ],
            'operation-mode' => 'cdn-friendly',
        ];

        $migrated = Config::migrateToV2($v2);

        // User-set keys preserved exactly...
        $this->assertSame(2, $migrated['config-version']);
        $this->assertSame('cdn-friendly', $migrated['operation-mode']);
        $this->assertTrue($migrated['formats']['avif']['enabled']);
        $this->assertSame(45, $migrated['formats']['avif']['quality']);
        $this->assertSame(3, $migrated['formats']['avif']['speed']);
        // ...and the new per-format key is backfilled from defaults.
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
        // A user who reordered + deactivated AVIF converters must keep that exact list through
        // migration — it must NOT be clobbered by the default stack.
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

    // --- idempotency ----------------------------------------------------------

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

    // --- partial / corrupt formats sections -----------------------------------

    public function testPartialFormatsSectionIsFilledFromDefaults(): void
    {
        // A config that has an avif section missing 'speed' (e.g. an intermediate save) must get
        // the missing key filled WITHOUT losing the user's quality choice.
        $config = [
            'config-version' => 2,
            'formats' => [
                'avif' => ['enabled' => true, 'quality' => 25],
            ],
        ];

        $migrated = Config::migrateToV2($config);

        $this->assertTrue($migrated['formats']['avif']['enabled']);
        $this->assertSame(25, $migrated['formats']['avif']['quality']);   // preserved
        $this->assertSame(6, $migrated['formats']['avif']['speed']);      // filled from default
        $this->assertTrue($migrated['formats']['webp']['enabled']);       // webp added
    }

    public function testCorruptFormatsSectionIsReplacedWithDefaults(): void
    {
        // 'formats' present but not an array -> treat as v1 and take defaults wholesale.
        $config = [
            'operation-mode' => 'varied-image-responses',
            'formats' => 'not-an-array',
        ];

        $migrated = Config::migrateToV2($config);

        $this->assertSame(Config::getDefaultFormats(), $migrated['formats']);
        $this->assertSame(2, $migrated['config-version']);
    }

    // --- defaults shape -------------------------------------------------------

    public function testGetDefaultFormatsShape(): void
    {
        $formats = Config::getDefaultFormats();

        $this->assertSame(['webp', 'avif'], array_keys($formats));

        // WebP carries ONLY an enabled flag (its real settings stay top-level by design).
        $this->assertSame(['enabled' => true], $formats['webp']);

        // AVIF owns its own settings, including its converter stack.
        $this->assertSame(
            ['enabled' => false, 'quality' => 30, 'speed' => 6],
            array_diff_key($formats['avif'], ['converters' => true])
        );

        // The default AVIF converter stack: every backend, in priority order, all active
        // (no 'deactivated' flag), seeded from AvifStack::defaultConverterIds().
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
