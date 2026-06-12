<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;

// The CLI command class extends \WP_CLI_Command, which only exists under WP-CLI. Provide a
// minimal stub so the class is loadable here — we only exercise its PURE static aggregation
// helper (aggregateSummaries), which touches no WP-CLI APIs.
if (!class_exists('\WP_CLI_Command')) {
    class WP_CLI_Command {}
    \class_alias('MagicConvert\\Tests\\WP_CLI_Command', 'WP_CLI_Command');
}

use MagicConvert\CLI;

/**
 * Tests for CLI::aggregateSummaries() — the pure aggregation of per-shard '#MC-SUMMARY'
 * payloads into overall + per-format totals (Phase 2.5). Factored out of the proc_open
 * orchestration precisely so it can be unit-tested without spawning processes.
 */
class CliAggregateSummariesTest extends TestCase
{
    public function testAggregatesOverallCountersAcrossShards(): void
    {
        $agg = CLI::aggregateSummaries([
            ['converted' => 10, 'failed' => 1, 'org_bytes' => 1000, 'webp_bytes' => 400],
            ['converted' => 5,  'failed' => 0, 'org_bytes' => 500,  'webp_bytes' => 250],
        ]);
        $this->assertSame(15, $agg['converted']);
        $this->assertSame(1, $agg['failed']);
        $this->assertSame(1500, $agg['org_bytes']);
        $this->assertSame(650, $agg['webp_bytes']);
    }

    public function testMergesPerFormatTalliesAcrossShards(): void
    {
        $agg = CLI::aggregateSummaries([
            ['converted' => 4, 'failed' => 0, 'org_bytes' => 800, 'webp_bytes' => 300, 'formats' => [
                'webp' => ['converted' => 2, 'failed' => 0, 'org_bytes' => 400, 'out_bytes' => 150],
                'avif' => ['converted' => 2, 'failed' => 0, 'org_bytes' => 400, 'out_bytes' => 150],
            ]],
            ['converted' => 3, 'failed' => 1, 'org_bytes' => 600, 'webp_bytes' => 250, 'formats' => [
                'webp' => ['converted' => 2, 'failed' => 0, 'org_bytes' => 400, 'out_bytes' => 170],
                'avif' => ['converted' => 1, 'failed' => 1, 'org_bytes' => 200, 'out_bytes' => 80],
            ]],
        ]);

        $this->assertSame(7, $agg['converted']);
        $this->assertSame(1, $agg['failed']);

        $this->assertSame(
            ['converted' => 4, 'failed' => 0, 'org_bytes' => 800, 'out_bytes' => 320],
            $agg['formats']['webp']
        );
        $this->assertSame(
            ['converted' => 3, 'failed' => 1, 'org_bytes' => 600, 'out_bytes' => 230],
            $agg['formats']['avif']
        );
    }

    public function testToleratesMissingFormatsBlockAndNonArrayEntries(): void
    {
        // A legacy/old shard without a 'formats' block must not break aggregation; non-array
        // entries (e.g. a shard that never emitted a summary) are skipped.
        $agg = CLI::aggregateSummaries([
            ['converted' => 2, 'failed' => 0, 'org_bytes' => 100, 'webp_bytes' => 40],  // no 'formats'
            null,
            'garbage',
            ['converted' => 1, 'failed' => 0, 'org_bytes' => 50, 'webp_bytes' => 20, 'formats' => [
                'webp' => ['converted' => 1, 'failed' => 0, 'org_bytes' => 50, 'out_bytes' => 20],
            ]],
        ]);

        $this->assertSame(3, $agg['converted']);
        $this->assertSame(150, $agg['org_bytes']);
        $this->assertSame(['converted' => 1, 'failed' => 0, 'org_bytes' => 50, 'out_bytes' => 20], $agg['formats']['webp']);
        $this->assertArrayNotHasKey('avif', $agg['formats']);
    }

    public function testEmptyInputYieldsZeroedAggregate(): void
    {
        $agg = CLI::aggregateSummaries([]);
        $this->assertSame(
            ['converted' => 0, 'failed' => 0, 'org_bytes' => 0, 'webp_bytes' => 0, 'formats' => []],
            $agg
        );
    }
}
