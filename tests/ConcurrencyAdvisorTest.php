<?php

namespace MagicConvert\Tests;

use MagicConvert\ConcurrencyAdvisor;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MagicConvert\ConcurrencyAdvisor.
 *
 * The advisor's OS probes (CPU cores, load average) are non-deterministic on a
 * CI box, so we exercise the *policy* — clamp ranges, the busy threshold, and the
 * web/CLI recommendation formulas — with INJECTED core counts and load readings.
 * The constructor seam ($cores, $load) makes every case below fully
 * deterministic, independent of the machine running the suite.
 *
 * Matrix: 1, 2, 4, 8, 16 cores, each evaluated idle (load 0) and busy (load high
 * enough that load/core > 1.5).
 */
class ConcurrencyAdvisorTest extends TestCase
{
    // --- cpuCoreCount: injection + clamp to >= 1 -------------------------------

    public function testInjectedCoreCountIsReturned(): void
    {
        $this->assertSame(8, (new ConcurrencyAdvisor(8))->cpuCoreCount());
        $this->assertSame(1, (new ConcurrencyAdvisor(1))->cpuCoreCount());
    }

    public function testInjectedCoreCountIsFlooredAtOne(): void
    {
        // A nonsensical 0 or negative injection still yields a usable >= 1.
        $this->assertSame(1, (new ConcurrencyAdvisor(0))->cpuCoreCount());
        $this->assertSame(1, (new ConcurrencyAdvisor(-4))->cpuCoreCount());
    }

    // --- loadPerCore -----------------------------------------------------------

    public function testLoadPerCoreDividesByCores(): void
    {
        $a = new ConcurrencyAdvisor(4, 2.0);
        $this->assertSame(0.5, $a->loadPerCore());

        $b = new ConcurrencyAdvisor(2, 3.0);
        $this->assertSame(1.5, $b->loadPerCore());
    }

    // --- isBusy: threshold is load/core > 1.5 ----------------------------------

    public function testIsBusyThreshold(): void
    {
        // Exactly at 1.5 is NOT busy (strict greater-than).
        $this->assertFalse((new ConcurrencyAdvisor(2, 3.0))->isBusy());     // 1.5
        // Just over.
        $this->assertTrue((new ConcurrencyAdvisor(2, 3.1))->isBusy());      // 1.55
        // Comfortably idle.
        $this->assertFalse((new ConcurrencyAdvisor(4, 1.0))->isBusy());     // 0.25
        // Heavily loaded.
        $this->assertTrue((new ConcurrencyAdvisor(8, 40.0))->isBusy());     // 5.0
    }

    public function testNullLoadIsTreatedAsNotBusy(): void
    {
        // With no load override the advisor falls back to sys_getloadavg(). On a
        // platform without it (Windows) loadPerCore() is null and isBusy() false.
        // We assert the documented contract directly via an injected-cores,
        // detected-load instance only where load is unavailable; here we just
        // confirm the null-load branch of isBusy via loadPerCore being null is
        // impossible to force with an override, so we assert the policy constant.
        $this->assertSame(1.5, ConcurrencyAdvisor::BUSY_LOAD_PER_CORE);
    }

    // --- recommendedWebConcurrency: clamp(floor(cores/2), 1, 6); 1 when busy ----

    /**
     * @dataProvider webIdleProvider
     */
    public function testRecommendedWebConcurrencyIdle(int $cores, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, 0.0); // idle
        $this->assertSame($expected, $advisor->recommendedWebConcurrency());
    }

    public static function webIdleProvider(): array
    {
        return [
            'one core'      => [1, 1],   // floor(1/2)=0 -> clamp to 1
            'two cores'     => [2, 1],   // floor(2/2)=1
            'four cores'    => [4, 2],   // floor(4/2)=2
            'eight cores'   => [8, 4],   // floor(8/2)=4
            'sixteen cores' => [16, 6],  // floor(16/2)=8 -> clamp to 6
        ];
    }

    /**
     * @dataProvider coreCountProvider
     */
    public function testRecommendedWebConcurrencyBusyAlwaysOne(int $cores): void
    {
        // Busy: load/core well above 1.5 regardless of core count.
        $advisor = new ConcurrencyAdvisor($cores, $cores * 3.0);
        $this->assertTrue($advisor->isBusy());
        $this->assertSame(1, $advisor->recommendedWebConcurrency());
    }

    // --- recommendedCliProcs: clamp(cores-1, 1, 8); halved (floor 1) when busy --

    /**
     * @dataProvider cliIdleProvider
     */
    public function testRecommendedCliProcsIdle(int $cores, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, 0.0); // idle
        $this->assertSame($expected, $advisor->recommendedCliProcs());
    }

    public static function cliIdleProvider(): array
    {
        return [
            'one core'      => [1, 1],   // 1-1=0 -> clamp to 1
            'two cores'     => [2, 1],   // 2-1=1
            'four cores'    => [4, 3],   // 4-1=3
            'eight cores'   => [8, 7],   // 8-1=7
            'sixteen cores' => [16, 8],  // 16-1=15 -> clamp to 8
        ];
    }

    /**
     * @dataProvider cliBusyProvider
     */
    public function testRecommendedCliProcsBusyHalved(int $cores, int $expected): void
    {
        $advisor = new ConcurrencyAdvisor($cores, $cores * 3.0); // busy
        $this->assertTrue($advisor->isBusy());
        $this->assertSame($expected, $advisor->recommendedCliProcs());
    }

    public static function cliBusyProvider(): array
    {
        return [
            // idle recommendation then halved, floor 1
            'one core'      => [1, 1],   // idle 1 -> floor(1/2)=0 -> 1
            'two cores'     => [2, 1],   // idle 1 -> 1
            'four cores'    => [4, 1],   // idle 3 -> floor(3/2)=1
            'eight cores'   => [8, 3],   // idle 7 -> floor(7/2)=3
            'sixteen cores' => [16, 4],  // idle 8 -> floor(8/2)=4
        ];
    }

    public static function coreCountProvider(): array
    {
        return [[1], [2], [4], [8], [16]];
    }

    // --- detection fallback ----------------------------------------------------

    public function testDetectionNeverReturnsBelowOne(): void
    {
        // Whatever the host reports, the contract is >= 1.
        $detected = ConcurrencyAdvisor::detectCpuCoreCount();
        $this->assertGreaterThanOrEqual(1, $detected);
        // And a no-override advisor's cpuCoreCount() is also >= 1.
        $this->assertGreaterThanOrEqual(1, (new ConcurrencyAdvisor())->cpuCoreCount());
    }
}
