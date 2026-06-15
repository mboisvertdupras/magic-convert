<?php

namespace MagicConvert\Tests;

use MagicConvert\ConcurrencyAdvisor;
use PHPUnit\Framework\TestCase;

class ConcurrencyAdvisorTest extends TestCase
{
    private const AMPLE_MEMORY = 274877906944;

    private const GIB = 1073741824;

    public function testInjectedCoreCountIsReturned(): void
    {
        $this->assertSame(8, (new ConcurrencyAdvisor(8))->cpuCoreCount());
        $this->assertSame(1, (new ConcurrencyAdvisor(1))->cpuCoreCount());
    }

    public function testInjectedCoreCountIsFlooredAtOne(): void
    {
        $this->assertSame(1, (new ConcurrencyAdvisor(0))->cpuCoreCount());
        $this->assertSame(1, (new ConcurrencyAdvisor(-4))->cpuCoreCount());
    }

    public function testLoadPerCoreDividesByCores(): void
    {
        $a = new ConcurrencyAdvisor(4, 2.0);
        $this->assertSame(0.5, $a->loadPerCore());

        $b = new ConcurrencyAdvisor(2, 3.0);
        $this->assertSame(1.5, $b->loadPerCore());
    }

    public function testIsBusyThreshold(): void
    {
        $this->assertFalse((new ConcurrencyAdvisor(2, 3.0))->isBusy());
        $this->assertTrue((new ConcurrencyAdvisor(2, 3.1))->isBusy());
        $this->assertFalse((new ConcurrencyAdvisor(4, 1.0))->isBusy());
        $this->assertTrue((new ConcurrencyAdvisor(8, 40.0))->isBusy());
    }

    public function testNullLoadIsTreatedAsNotBusy(): void
    {
        $this->assertSame(1.5, ConcurrencyAdvisor::BUSY_LOAD_PER_CORE);
    }

    /**
     * @dataProvider webIdleProvider
     */
    public function testRecommendedWebConcurrencyIdle(int $cores, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, 0.0, self::AMPLE_MEMORY);
        $this->assertSame($expected, $advisor->recommendedWebConcurrency());
    }

    public static function webIdleProvider(): array
    {
        return [
            'one core'      => [1, 1],
            'two cores'     => [2, 1],
            'four cores'    => [4, 2],
            'eight cores'   => [8, 4],
            'sixteen cores' => [16, 8],
        ];
    }

    /**
     * @dataProvider coreCountProvider
     */
    public function testRecommendedWebConcurrencyIgnoresLoad(int $cores): void
    {
        $idle = new ConcurrencyAdvisor($cores, 0.0, self::AMPLE_MEMORY);
        $busy = new ConcurrencyAdvisor($cores, $cores * 3.0, self::AMPLE_MEMORY);
        $this->assertTrue($busy->isBusy());
        $this->assertSame(
            $idle->recommendedWebConcurrency(),
            $busy->recommendedWebConcurrency()
        );
    }

    /**
     * @dataProvider webFormatProvider
     */
    public function testRecommendedWebConcurrencyForFormat(int $cores, string $format, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, 0.0, self::AMPLE_MEMORY);
        $this->assertSame($expected, $advisor->recommendedWebConcurrencyForFormat($format));
    }

    public static function webFormatProvider(): array
    {
        return [
            'avif 2 cores'   => [2, 'avif', 1],
            'avif 4 cores'   => [4, 'avif', 2],
            'avif 10 cores'  => [10, 'avif', 5],
            'avif 16 cores'  => [16, 'avif', 8],
            'avif 32 cores'  => [32, 'avif', 8],
            'webp 4 cores'   => [4, 'webp', 4],
            'webp 10 cores'  => [10, 'webp', 8],
            'webp 16 cores'  => [16, 'webp', 8],
        ];
    }

    public function testWebTargetsMapsEachFormat(): void
    {
        $advisor = new ConcurrencyAdvisor(10, 0.0, self::AMPLE_MEMORY);
        $this->assertSame(
            ['webp' => 8, 'avif' => 5],
            $advisor->webTargets(['webp', 'avif'])
        );
    }

    /**
     * @dataProvider cliIdleProvider
     */
    public function testRecommendedCliProcsIdle(int $cores, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, 0.0, self::AMPLE_MEMORY);
        $this->assertSame($expected, $advisor->recommendedCliProcs());
    }

    public static function cliIdleProvider(): array
    {
        return [
            'one core'      => [1, 1],
            'two cores'     => [2, 1],
            'four cores'    => [4, 3],
            'eight cores'   => [8, 7],
            'sixteen cores' => [16, 8],
        ];
    }

    /**
     * @dataProvider cliBusyProvider
     */
    public function testRecommendedCliProcsBusyHalved(int $cores, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, $cores * 3.0, self::AMPLE_MEMORY);
        $this->assertTrue($advisor->isBusy());
        $this->assertSame($expected, $advisor->recommendedCliProcs());
    }

    public static function cliBusyProvider(): array
    {
        return [
            'one core'      => [1, 1],
            'two cores'     => [2, 1],
            'four cores'    => [4, 1],
            'eight cores'   => [8, 3],
            'sixteen cores' => [16, 4],
        ];
    }

    public static function coreCountProvider(): array
    {
        return [[1], [2], [4], [8], [16]];
    }

    public function testDetectionNeverReturnsBelowOne(): void
    {
        $detected = ConcurrencyAdvisor::detectCpuCoreCount();
        $this->assertGreaterThanOrEqual(1, $detected);
        $this->assertGreaterThanOrEqual(1, (new ConcurrencyAdvisor())->cpuCoreCount());
    }

    public function testInjectedAvailableMemoryIsReturned(): void
    {
        $this->assertSame(5 * self::GIB, (new ConcurrencyAdvisor(8, 0.0, 5 * self::GIB))->availableMemoryBytes());
        $this->assertSame(0, (new ConcurrencyAdvisor(8, 0.0, -100))->availableMemoryBytes());
    }

    public function testMemoryBudgetDividesUsableMemoryByReserve(): void
    {
        $this->assertSame(6, ConcurrencyAdvisor::memoryBudget(8 * self::GIB, self::GIB));
        $this->assertSame(2, ConcurrencyAdvisor::memoryBudget(3 * self::GIB, self::GIB));
    }

    public function testMemoryBudgetIsNullWhenMemoryUnknown(): void
    {
        $this->assertNull(ConcurrencyAdvisor::memoryBudget(null, self::GIB));
    }

    public function testMemoryBudgetNeverDropsBelowOne(): void
    {
        $this->assertSame(1, ConcurrencyAdvisor::memoryBudget(0, self::GIB));
        $this->assertSame(1, ConcurrencyAdvisor::memoryBudget(100, self::GIB));
    }

    public function testReserveBytesIsLargerForAvifThanWebp(): void
    {
        $this->assertGreaterThan(
            ConcurrencyAdvisor::reserveBytesForFormat('webp'),
            ConcurrencyAdvisor::reserveBytesForFormat('avif')
        );
    }

    public function testConcurrencyForFormatIsCappedByAvailableMemory(): void
    {
        $this->assertSame(3, ConcurrencyAdvisor::concurrencyForFormat('avif', 16, 4 * self::GIB, 8));
    }

    public function testConcurrencyForFormatIsCpuBoundWhenMemoryAmple(): void
    {
        $this->assertSame(8, ConcurrencyAdvisor::concurrencyForFormat('avif', 16, self::AMPLE_MEMORY, 8));
    }

    public function testConcurrencyForFormatIsCpuOnlyWhenMemoryUnknown(): void
    {
        $this->assertSame(8, ConcurrencyAdvisor::concurrencyForFormat('avif', 16, null, 8));
        $this->assertSame(1, ConcurrencyAdvisor::concurrencyForFormat('avif', 2, null, 8));
    }

    public function testWebpAllowsMoreConcurrencyThanAvifUnderSameMemory(): void
    {
        $avif = ConcurrencyAdvisor::concurrencyForFormat('avif', 16, 3 * self::GIB, 8);
        $webp = ConcurrencyAdvisor::concurrencyForFormat('webp', 16, 3 * self::GIB, 8);
        $this->assertSame(2, $avif);
        $this->assertSame(8, $webp);
    }

    public function testWebConcurrencyIsConstrainedByMemoryEndToEnd(): void
    {
        $advisor = new ConcurrencyAdvisor(16, 0.0, 4 * self::GIB);
        $this->assertSame(3, $advisor->recommendedWebConcurrencyForFormat('avif'));
    }

    public function testWebHardCeilingIsMaxAcrossFormats(): void
    {
        $advisor = new ConcurrencyAdvisor(16, 0.0, self::GIB);
        $this->assertSame(1, $advisor->recommendedWebConcurrencyForFormat('avif'));
        $this->assertSame(3, $advisor->recommendedWebConcurrencyForFormat('webp'));
        $this->assertSame(3, $advisor->webHardCeiling(['avif', 'webp']));
    }

    public function testCliProcsConstrainedByMemory(): void
    {
        $advisor = new ConcurrencyAdvisor(16, 0.0, 2 * self::GIB);
        $this->assertSame(1, $advisor->recommendedCliProcs());
    }

    public function testParseMemAvailableConvertsKbToBytes(): void
    {
        $meminfo = "MemTotal:       16384000 kB\nMemFree:         1000000 kB\nMemAvailable:    2097152 kB\n";
        $this->assertSame(2097152 * 1024, ConcurrencyAdvisor::parseMemAvailableBytes($meminfo));
    }

    public function testParseMemAvailableReturnsNullWhenMissing(): void
    {
        $this->assertNull(ConcurrencyAdvisor::parseMemAvailableBytes("MemTotal: 100 kB\n"));
        $this->assertNull(ConcurrencyAdvisor::parseMemAvailableBytes(''));
    }

    public function testParseCgroupLimitBytes(): void
    {
        $this->assertSame(1073741824, ConcurrencyAdvisor::parseCgroupLimitBytes("1073741824\n"));
        $this->assertNull(ConcurrencyAdvisor::parseCgroupLimitBytes("max\n"));
        $this->assertNull(ConcurrencyAdvisor::parseCgroupLimitBytes('0'));
        $this->assertNull(ConcurrencyAdvisor::parseCgroupLimitBytes('not-a-number'));
        $this->assertNull(ConcurrencyAdvisor::parseCgroupLimitBytes(''));
    }
}
