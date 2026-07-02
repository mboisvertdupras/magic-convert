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

    public function encode(string $source, string $destination, array $options, $logger): void
    {
        self::ensureVendorAutoloader();
        try {
            \WebPConvert\WebPConvert::convert($source, $destination, $options, $logger);
        } catch (\WebPConvert\Exceptions\WebPConvertException $e) {
            throw new FormatEncodeException($e->getMessage(), [], $e);
        } catch (\Throwable $e) {
            throw new FormatEncodeException($e->getMessage(), [], $e);
        }
    }

    public function encodeWith(string $converterId, string $source, string $destination, array $options, $logger): void
    {
        self::ensureVendorAutoloader();
        try {
            $logger->logLn('Converter set to: ' . $converterId);
            $logger->logLn('');
            $converterObj = \WebPConvert\Convert\ConverterFactory::makeConverter($converterId, $source, $destination, $options, $logger);
            $converterObj->doConvert();
        } catch (\WebPConvert\Exceptions\WebPConvertException $e) {
            throw new FormatEncodeException($e->getMessage(), [], $e);
        } catch (\Throwable $e) {
            throw new FormatEncodeException($e->getMessage(), [], $e);
        }
    }

    public function selfTest(): array
    {
        $rows = [];
        foreach ($this->converterIds() as $id) {
            $rows[] = [
                'id' => $id,
                'label' => $id,
            ];
        }
        return $rows;
    }

    public function memorySafetyMode(): string
    {
        return 'in-process';
    }

    private static function ensureVendorAutoloader(): void
    {
        if (class_exists('\WebPConvert\WebPConvert')) {
            return;
        }
        if (defined('MAGIC_CONVERT_PLUGIN_DIR')) {
            $autoload = MAGIC_CONVERT_PLUGIN_DIR . '/vendor/autoload.php';
            if (is_file($autoload)) {
                include_once $autoload;
            }
        }
    }
}
