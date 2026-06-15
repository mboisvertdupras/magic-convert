<?php

namespace MagicConvert\Tests;

use MagicConvert\ShardFilter;
use PHPUnit\Framework\TestCase;

class ShardFilterTest extends TestCase
{
    /**
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
            $name = 'image-' . $i . (($i % 7 === 0) ? ' café' : '') . '.' . $ext;
            $paths[] = sprintf('%s/%04d/%02d/%s', $root, $year, $month, $name);
        }
        return $paths;
    }

    /**
     * @dataProvider shardCountProvider
     */
    public function testEveryPathLandsInExactlyOneShard($n): void
    {
        $paths = $this->pathCorpus(500);

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

    public function testSingleShardOwnsEverything(): void
    {
        foreach ($this->pathCorpus(100) as $path) {
            $this->assertTrue(ShardFilter::belongs($path, 1, 1));
        }
    }

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

    public function testParseSpecAcceptsValid(): void
    {
        $this->assertSame([3, 8], ShardFilter::parseSpec('3/8'));
        $this->assertSame([1, 1], ShardFilter::parseSpec('1/1'));
        $this->assertSame([64, 64], ShardFilter::parseSpec('64/64'));
        $this->assertSame([1, 64], ShardFilter::parseSpec('1/64'));
    }

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

    public function testParseSpecBoundaryAtMaxShards(): void
    {
        $this->assertSame([64, 64], ShardFilter::parseSpec('64/64'));
        $this->expectException(\InvalidArgumentException::class);
        ShardFilter::parseSpec('1/65');
    }
}
