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

    public function testAvifOptionDefaultsMatchConfigSource(): void
    {
        $avifDefaults = Config::getDefaultFormats()['avif'];
        $expected = [
            'quality' => $avifDefaults['quality'],
            'speed' => $avifDefaults['speed'],
        ];
        $this->assertSame(['quality' => 30, 'speed' => 6], $expected);
        $this->assertSame($expected, ProviderRegistry::byId('avif')->optionDefaults());
    }

    public function testWebpOptionDefaultsAreEmpty(): void
    {
        $this->assertSame([], ProviderRegistry::byId('webp')->optionDefaults());
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
