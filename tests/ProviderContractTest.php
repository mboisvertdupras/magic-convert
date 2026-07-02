<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\Avif\AvifStack;
use MagicConvert\Avif\AvifStackException;
use MagicConvert\ConcurrencyAdvisor;
use MagicConvert\Config;
use MagicConvert\ConvertersHelper;
use MagicConvert\Format\FormatEncodeException;
use MagicConvert\Format\FormatProvider;
use MagicConvert\Format\ProviderRegistry;
use MagicConvert\OutputFormat;

class ProviderContractTest extends TestCase
{
    public function providerIds(): array
    {
        return [
            'webp' => ['webp'],
            'avif' => ['avif'],
        ];
    }

    public function testRegistryIdsMatchOutputFormatIds(): void
    {
        $this->assertSame(OutputFormat::ids(), array_keys(ProviderRegistry::all()));
    }

    public function testByIdReturnsSameInstanceTwice(): void
    {
        foreach (OutputFormat::ids() as $id) {
            $this->assertSame(ProviderRegistry::byId($id), ProviderRegistry::byId($id));
        }
    }

    public function testUnknownIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ProviderRegistry::byId('jxl');
    }

    public function testAllProvidersImplementInterface(): void
    {
        foreach (ProviderRegistry::all() as $provider) {
            $this->assertInstanceOf(FormatProvider::class, $provider);
        }
    }

    /**
     * @dataProvider providerIds
     */
    public function testIdMatchesRegistryKey(string $id): void
    {
        $this->assertSame($id, ProviderRegistry::byId($id)->id());
    }

    /**
     * @dataProvider providerIds
     */
    public function testConverterIdsAreNonEmptyStrings(string $id): void
    {
        $ids = ProviderRegistry::byId($id)->converterIds();
        $this->assertIsArray($ids);
        $this->assertNotEmpty($ids);
        foreach ($ids as $cid) {
            $this->assertIsString($cid);
            $this->assertNotSame('', $cid);
        }
    }

    public function testAvifConverterIdsMatchAvifStack(): void
    {
        $this->assertSame(
            AvifStack::defaultConverterIds(),
            ProviderRegistry::byId('avif')->converterIds()
        );
    }

    public function testWebpConverterIdsMatchConvertersHelper(): void
    {
        $this->assertSame(
            ConvertersHelper::getDefaultConverterNames(),
            ProviderRegistry::byId('webp')->converterIds()
        );
    }

    public function testConfigAvifDefaultsAreBuiltFromProviderOptionDefaults(): void
    {
        // The provider is now the single source of truth for the avif option
        // defaults; Config::getDefaultFormats() consumes them.
        $providerDefaults = ProviderRegistry::byId('avif')->optionDefaults();
        $this->assertSame(['quality' => 30, 'speed' => 6], $providerDefaults);

        $avifBlock = Config::getDefaultFormats()['avif'];
        $this->assertSame($providerDefaults['quality'], $avifBlock['quality']);
        $this->assertSame($providerDefaults['speed'], $avifBlock['speed']);
    }

    public function testWebpOptionDefaultsAreEmpty(): void
    {
        $this->assertSame([], ProviderRegistry::byId('webp')->optionDefaults());
    }

    public function testAbstractAvifConverterFallbacksMatchProviderDefaults(): void
    {
        // AbstractAvifConverter keeps its own last-resort clamp fallbacks because
        // the Avif namespace must not depend on the Format namespace (that would be
        // circular: Format\AvifProvider already depends on Avif\AvifStack). This test
        // pins them equal to the single source so any drift fails CI.
        $defaults = ProviderRegistry::byId('avif')->optionDefaults();
        $this->assertSame($defaults['quality'], \MagicConvert\Avif\AbstractAvifConverter::DEFAULT_QUALITY);
        $this->assertSame($defaults['speed'], \MagicConvert\Avif\AbstractAvifConverter::DEFAULT_SPEED);
    }

    public function testWebpNormalizeOptionsPassesThroughConvertSlice(): void
    {
        $convert = ['metadata' => 'all', 'converters' => [['converter' => 'cwebp']]];
        $wodOptions = [
            'wod' => ['enable-logging' => false],
            'webp-convert' => ['fail' => 'original', 'convert' => $convert],
            'formats' => ['webp' => ['enabled' => true], 'avif' => ['enabled' => false]],
        ];
        $this->assertSame($convert, ProviderRegistry::byId('webp')->normalizeOptions($wodOptions));
    }

    public function testWebpNormalizeOptionsReturnsEmptyWhenConvertSliceMissing(): void
    {
        $this->assertSame([], ProviderRegistry::byId('webp')->normalizeOptions(['formats' => []]));
    }

    public function testAvifNormalizeOptionsAppliesDefaultsWhenKeysMissing(): void
    {
        $convert = ['metadata' => 'all'];
        $wodOptions = [
            'webp-convert' => ['convert' => $convert],
            'formats' => ['avif' => ['enabled' => true]],
        ];
        $result = ProviderRegistry::byId('avif')->normalizeOptions($wodOptions);

        $this->assertSame('all', $result['metadata']);
        $this->assertSame(30, $result['avif']['quality']);
        $this->assertSame(6, $result['avif']['speed']);
        $this->assertSame([], $result['avif']['converters']);
    }

    public function testAvifNormalizeOptionsMissingDefaultsComeFromOptionDefaults(): void
    {
        $wodOptions = [
            'webp-convert' => ['convert' => []],
            'formats' => ['avif' => []],
        ];
        $result = ProviderRegistry::byId('avif')->normalizeOptions($wodOptions);
        $defaults = ProviderRegistry::byId('avif')->optionDefaults();

        $this->assertSame($defaults['quality'], $result['avif']['quality']);
        $this->assertSame($defaults['speed'], $result['avif']['speed']);
    }

    public function testAvifNormalizeOptionsUsesProvidedValuesWhenPresent(): void
    {
        $convert = ['metadata' => 'none'];
        $converters = [['converter' => 'vips'], ['converter' => 'gd']];
        $wodOptions = [
            'webp-convert' => ['convert' => $convert],
            'formats' => [
                'avif' => ['enabled' => true, 'quality' => 45, 'speed' => 3, 'converters' => $converters],
            ],
        ];
        $result = ProviderRegistry::byId('avif')->normalizeOptions($wodOptions);

        $this->assertSame(
            [
                'metadata' => 'none',
                'avif' => ['quality' => 45, 'speed' => 3, 'converters' => $converters],
            ],
            $result
        );
    }

    /**
     * @dataProvider providerIds
     */
    public function testMemoryReserveBytesMatchesAdvisor(string $id): void
    {
        $this->assertSame(
            ConcurrencyAdvisor::reserveBytesForFormat($id),
            ProviderRegistry::byId($id)->memoryReserveBytes()
        );
    }

    public function testProvidersDeclareTheReserveByteLiterals(): void
    {
        $this->assertSame(1073741824, ProviderRegistry::byId('avif')->memoryReserveBytes());
        $this->assertSame(268435456, ProviderRegistry::byId('webp')->memoryReserveBytes());
    }

    /**
     * @dataProvider providerIds
     */
    public function testAdvisorReserveBytesComeFromProviders(string $id): void
    {
        $this->assertSame(
            ProviderRegistry::byId($id)->memoryReserveBytes(),
            ConcurrencyAdvisor::reserveBytesForFormat($id)
        );
    }

    /**
     * @dataProvider providerIds
     */
    public function testConcurrencyWeightMatchesAdvisor(string $id): void
    {
        $cores = 12;
        $perFormat = ConcurrencyAdvisor::concurrencyForFormat($id, $cores, null, $cores);
        $expectedWeight = intdiv($cores, $perFormat);
        $this->assertSame($expectedWeight, ProviderRegistry::byId($id)->concurrencyWeight());
    }

    public function testAvifStackExceptionIsAFormatEncodeException(): void
    {
        $reasons = ['gd' => 'no avif', 'vips' => 'missing'];
        $e = new AvifStackException('boom', $reasons);
        $this->assertInstanceOf(FormatEncodeException::class, $e);
        $this->assertSame($reasons, $e->perConverterReasons());
        $this->assertSame($reasons, $e->getReasons());
    }

    public function testPlainFormatEncodeExceptionHasEmptyReasons(): void
    {
        $this->assertSame([], (new FormatEncodeException('x'))->perConverterReasons());
    }
}
