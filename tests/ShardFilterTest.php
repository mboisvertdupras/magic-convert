<?php

namespace MagicConvert\Tests;

use MagicConvert\ShardFilter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MagicConvert\ShardFilter — the stable partition behind WP-CLI's
 * parallel-by-default bulk conversion.
 *
 * The two properties that make sharding correct are tested directly:
 *
 *   1. DISJOINT + COMPLETE: over a corpus of synthetic root-relative paths, for
 *      any shard count n every path lands in exactly one shard (no file is
 *      skipped, none is converted twice).
 *   2. DETERMINISTIC: belongs() is a pure function of its inputs — repeated calls
 *      give the same answer (this is what lets the parent and each independently-
 *      bootstrapped child agree on the partition without communicating).
 *
 * parseSpec() validation is exercised across accept/reject cases.
 */
class ShardFilterTest extends TestCase
{
    /**
     * Generate a deterministic corpus of plausible root-relative image paths.
     *
     * @return string[]
     */
    private function pathCorpus($count = 500)
    {
        $roots = ['uploads', 'themes', 'plugins', 'wp-content'];
        $exts  = ['jpg', 'jpeg', 'png'];
        $paths = [];
        for ($i = 0; $i < $count; $i++) {
            $root = $roots[$i % count($roots)];
            $year = 2018 + ($i % 8);
            $month = 1 + ($i % 12);
            $ext = $exts[$i % count($exts)];
            // Mix in the index so paths are unique, plus an occasional unicode
            // and space to stress the hashing of real-world filenames.
            $name = 'image-' . $i . (($i % 7 === 0) ? ' café' : '') . '.' . $ext;
            $paths[] = sprintf('%s/%04d/%02d/%s', $root, $year, $month, $name);
        }
        return $paths;
    }

    // --- belongs(): disjoint + complete cover ----------------------------------

    /**
     * For each n in {2,3,8}, every path in the corpus must belong to exactly one
     * shard. We count, per path, how many of the n shards claim it: that count
     * must always be exactly 1.
     *
     * @dataProvider shardCountProvider
     */
    public function testEveryPathLandsInExactlyOneShard($n): void
    {
        $paths = $this->pathCorpus(500);

        // Also assert completeness at the aggregate level: the sum of per-shard
        // sizes equals the corpus size.
        $perShardCounts = array_fill(1, $n, 0);

        foreach ($paths as $path) {
            $hits = 0;
            $owner = null;
            for ($shard = 1; $shard <= $n; $shard++) {
                if (ShardFilter::belongs($path, $shard, $n)) {
                    $hits++;
                    $owner = $shard;
                }
            }
            $this->assertSame(
                1,
                $hits,
                "Path '$path' was claimed by $hits shards (expected exactly 1) for n=$n"
            );
            $perShardCounts[$owner]++;
        }

        $this->assertSame(
            count($paths),
            array_sum($perShardCounts),
            "Shards for n=$n did not form a complete cover of the corpus"
        );
    }

    public function shardCountProvider(): array
    {
        return [
            'n=2' => [2],
            'n=3' => [3],
            'n=8' => [8],
        ];
    }

    /**
     * With a single shard, every path belongs to it (the degenerate sequential
     * case the parent uses when it decides not to fan out).
     */
    public function testSingleShardOwnsEverything(): void
    {
        foreach ($this->pathCorpus(100) as $path) {
            $this->assertTrue(ShardFilter::belongs($path, 1, 1));
        }
    }

    /**
     * The partition is non-trivial: for n=8 over 500 varied paths, every shard
     * should receive at least one path (guards against a hashing bug that funnels
     * everything into shard 1).
     */
    public function testPartitionIsNonTrivial(): void
    {
        $n = 8;
        $perShard = array_fill(1, $n, 0);
        foreach ($this->pathCorpus(500) as $path) {
            for ($shard = 1; $shard <= $n; $shard++) {
                if (ShardFilter::belongs($path, $shard, $n)) {
                    $perShard[$shard]++;
                    break;
                }
            }
        }
        foreach ($perShard as $shard => $count) {
            $this->assertGreaterThan(0, $count, "Shard $shard/$n received no paths");
        }
    }

    // --- belongs(): determinism ------------------------------------------------

    public function testBelongsIsDeterministicAcrossCalls(): void
    {
        foreach ($this->pathCorpus(200) as $path) {
            for ($n = 2; $n <= 8; $n++) {
                for ($shard = 1; $shard <= $n; $shard++) {
                    $a = ShardFilter::belongs($path, $shard, $n);
                    $b = ShardFilter::belongs($path, $shard, $n);
                    $this->assertSame($a, $b, "belongs() not deterministic for $path shard $shard/$n");
                }
            }
        }
    }

    // --- parseSpec(): accepted -------------------------------------------------

    public function testParseSpecAcceptsValid(): void
    {
        $this->assertSame([3, 8], ShardFilter::parseSpec('3/8'));
        $this->assertSame([1, 1], ShardFilter::parseSpec('1/1'));
        $this->assertSame([64, 64], ShardFilter::parseSpec('64/64'));
        $this->assertSame([1, 64], ShardFilter::parseSpec('1/64'));
    }

    // --- parseSpec(): rejected -------------------------------------------------

    /**
     * @dataProvider invalidSpecProvider
     */
    public function testParseSpecRejectsInvalid($spec): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ShardFilter::parseSpec($spec);
    }

    public function invalidSpecProvider(): array
    {
        return [
            'index 0'          => ['0/8'],
            'index exceeds n'  => ['9/8'],
            'non-numeric'      => ['x/y'],
            'n is zero'        => ['1/0'],
            'negative index'   => ['-1/8'],
            'negative n'       => ['1/-8'],
            'n over 64'        => ['1/65'],
            'index over 64'    => ['65/65'],
            'empty'            => [''],
            'no slash'         => ['38'],
            'trailing slash'   => ['3/'],
            'leading slash'    => ['/8'],
            'whitespace'       => [' 3/8 '],
            'float'            => ['3.0/8'],
            'three parts'      => ['1/2/3'],
        ];
    }

    /**
     * n at the 64 ceiling is accepted; n=65 is rejected — the boundary of
     * MAX_SHARDS.
     */
    public function testParseSpecBoundaryAtMaxShards(): void
    {
        $this->assertSame([64, 64], ShardFilter::parseSpec('64/64'));
        $this->expectException(\InvalidArgumentException::class);
        ShardFilter::parseSpec('1/65');
    }
}
