<?php

namespace MagicConvert\Format;

use MagicConvert\ConvertersHelper;

class WebPProvider implements FormatProvider
{
    private const ENCODE_RESERVE_BYTES = 268435456;

    public function id(): string
    {
        return 'webp';
    }

    public function converterIds(): array
    {
        return ConvertersHelper::getDefaultConverterNames();
    }

    public function optionDefaults(): array
    {
        return [];
    }

    public function normalizeOptions(array $options): array
    {
        if (isset($options['webp-convert']['convert']) && is_array($options['webp-convert']['convert'])) {
            return $options['webp-convert']['convert'];
        }
        return [];
    }

    public function memoryReserveBytes(): int
    {
        return self::ENCODE_RESERVE_BYTES;
    }

    public function concurrencyWeight(): int
    {
        return 1;
    }

    public function converterEntryFromConfig(array $config, string $converterId): ?array
    {
        $entry = ConvertersHelper::getConverterById($config, $converterId);
        return $entry === false ? null : $entry;
    }

    public function encode(string $source, string $destination, array $options, $logger): void
    {
        try {
            \WebPConvert\WebPConvert::convert($source, $destination, $options, $logger);
        } catch (\Throwable $e) {
            throw new FormatEncodeException($e->getMessage(), [], $e);
        }
    }

    public function encodeWith(string $converterId, string $source, string $destination, array $options, $logger): void
    {
        try {
            $logger->logLn('Converter set to: ' . $converterId);
            $logger->logLn('');
            $converterObj = \WebPConvert\Convert\ConverterFactory::makeConverter($converterId, $source, $destination, $options, $logger);
            $converterObj->doConvert();
        } catch (\Throwable $e) {
            throw new FormatEncodeException($e->getMessage(), [], $e);
        }
    }

    public function selfTest(): array
    {
        return [];
    }

    public function memorySafetyMode(): string
    {
        return 'in-process';
    }
}
