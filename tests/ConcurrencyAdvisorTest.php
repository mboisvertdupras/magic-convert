<?php

namespace MagicConvert\Tests;

use MagicConvert\ConcurrencyAdvisor;
use PHPUnit\Framework\TestCase;

class ConcurrencyAdvisorTest extends TestCase
{
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
        $advisor = new ConcurrencyAdvisor($cores, 0.0);
        $this->assertSame($expected, $advisor->recommendedWebConcurrency());
    }

    public static function webIdleProvider(): array
    {
        return [
            'one core'      => [1, 1],
            'two cores'     => [2, 1],
            'four cores'    => [4, 2],
            'eight cores'   => [8, 4],
            'sixteen cores' => [16, 6],
        ];
    }

    /**
     * @dataProvider coreCountProvider
     */
    public function testRecommendedWebConcurrencyBusyAlwaysOne(int $cores): void
    {
        $advisor = new ConcurrencyAdvisor($cores, $cores * 3.0);
        $this->assertTrue($advisor->isBusy());
        $this->assertSame(1, $advisor->recommendedWebConcurrency());
    }

    /**
     * @dataProvider cliIdleProvider
     */
    public function testRecommendedCliProcsIdle(int $cores, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, 0.0);
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
        $advisor = new ConcurrencyAdvisor($cores, $cores * 3.0);
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
}
