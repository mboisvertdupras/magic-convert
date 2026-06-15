<?php

namespace MagicConvert;

class ShardFilter
{
    const MAX_SHARDS = 64;

    public static function belongs($relativePath, $shard, $totalShards)
    {
        $totalShards = (int) $totalShards;
        $shard = (int) $shard;

        if ($totalShards < 1) {
            return true;
        }

        $residue = ((crc32((string) $relativePath) % $totalShards) + $totalShards) % $totalShards;

        return $residue === ($shard - 1);
    }

    /**
     * @return array{0:int,1:int}
     * @throws \InvalidArgumentException
     */
    public static function parseSpec($spec)
    {
        if (!is_string($spec) || !preg_match('/^([0-9]+)\/([0-9]+)$/', $spec, $m)) {
            throw new \InvalidArgumentException(
                'Invalid shard spec "' . (is_string($spec) ? $spec : gettype($spec)) .
                '". Expected the form "i/n", e.g. "3/8".'
            );
        }

        $i = (int) $m[1];
        $n = (int) $m[2];

        if ($n < 1 || $n > self::MAX_SHARDS) {
            throw new \InvalidArgumentException(
                'Invalid shard spec "' . $spec . '": total shards must be between 1 and ' .
                self::MAX_SHARDS . '.'
            );
        }
        if ($i < 1 || $i > $n) {
            throw new \InvalidArgumentException(
                'Invalid shard spec "' . $spec . '": shard index must be between 1 and ' . $n . '.'
            );
        }

        return [$i, $n];
    }
}
