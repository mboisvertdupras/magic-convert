<?php

namespace MagicConvert\Format;

class ProviderRegistry
{
    /** @var array<string,FormatProvider>|null */
    private static $providers = null;

    /**
     * @return array<string,FormatProvider>
     */
    private static function providers(): array
    {
        if (self::$providers === null) {
            self::$providers = [
                'webp' => new WebPProvider(),
                'avif' => new AvifProvider(),
            ];
        }
        return self::$providers;
    }

    /**
     * @param  string  $id
     * @return FormatProvider
     * @throws \InvalidArgumentException
     */
    public static function byId(string $id): FormatProvider
    {
        $providers = self::providers();
        if (!isset($providers[$id])) {
            throw new \InvalidArgumentException(
                'Unknown format provider id: ' . $id .
                '. Known providers: ' . implode(', ', array_keys($providers)) . '.'
            );
        }
        return $providers[$id];
    }

    /**
     * @return array<string,FormatProvider>
     */
    public static function all(): array
    {
        return self::providers();
    }
}
