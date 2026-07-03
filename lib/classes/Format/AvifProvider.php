<?php

namespace MagicConvert\Format;

use MagicConvert\Avif\AvifStack;

class AvifProvider implements FormatProvider
{
    private const ENCODE_RESERVE_BYTES = 1073741824;

    public function id(): string
    {
        return 'avif';
    }

    public function converterIds(): array
    {
        return AvifStack::defaultConverterIds();
    }

    public function optionDefaults(): array
    {
        return [
            'quality' => 30,
            'speed' => 6,
        ];
    }

    public function normalizeOptions(array $options): array
    {
        $convertOptions = (isset($options['webp-convert']['convert']) && is_array($options['webp-convert']['convert']))
            ? $options['webp-convert']['convert']
            : [];

        $avifCfg = (isset($options['formats']['avif']) && is_array($options['formats']['avif']))
            ? $options['formats']['avif']
            : [];

        $defaults = $this->optionDefaults();

        $convertOptions['avif'] = [
            'quality' => isset($avifCfg['quality']) ? intval($avifCfg['quality']) : $defaults['quality'],
            'speed' => isset($avifCfg['speed']) ? intval($avifCfg['speed']) : $defaults['speed'],
            'converters' => (isset($avifCfg['converters']) && is_array($avifCfg['converters']))
                ? $avifCfg['converters']
                : [],
        ];

        return $convertOptions;
    }

    public function memoryReserveBytes(): int
    {
        return self::ENCODE_RESERVE_BYTES;
    }

    public function concurrencyWeight(): int
    {
        return 2;
    }

    public function converterEntryFromConfig(array $config, string $converterId): ?array
    {
        return in_array($converterId, $this->converterIds(), true) ? ['converter' => $converterId] : null;
    }

    public function encode(string $source, string $destination, array $options, $logger): void
    {
        $avifConverterList = (isset($options['avif']['converters']) && is_array($options['avif']['converters']))
            ? $options['avif']['converters']
            : [];
        $stack = AvifStack::fromConverterList($avifConverterList);
        $this->runStack($stack, $source, $destination, $options, $logger);
    }

    public function encodeWith(string $converterId, string $source, string $destination, array $options, $logger): void
    {
        $stack = AvifStack::fromConverterList([['converter' => $converterId]]);
        $this->runStack($stack, $source, $destination, $options, $logger);
    }

    private function runStack(AvifStack $stack, string $source, string $destination, array $options, $logger): void
    {
        $avifOptions = $this->deriveAvifOptions($options);
        $logger->logLn('AVIF conversion (quality=' . $avifOptions['quality']
            . ', speed=' . $avifOptions['speed']
            . ', metadata=' . $avifOptions['metadata'] . ')');
        $logger->logLn('');

        $result = $stack->convert($source, $destination, $avifOptions);

        $logger->logLn($result['log']);
        $logger->logLn('');
        $logger->logLn('Converted with: ' . $result['converter']);
    }

    public function selfTest(): array
    {
        return (new AvifStack())->selfTest();
    }

    public function memorySafetyMode(): string
    {
        return (new AvifStack())->memorySafetyMode();
    }

    /**
     * @param  array  $convertOptions
     * @return array{quality:int,speed:int,metadata:string,jobs:(int|null)}
     */
    private function deriveAvifOptions($convertOptions): array
    {
        $avif = (is_array($convertOptions) && isset($convertOptions['avif']) && is_array($convertOptions['avif']))
            ? $convertOptions['avif']
            : [];

        $defaults = $this->optionDefaults();
        $quality = isset($avif['quality']) ? (int) $avif['quality'] : $defaults['quality'];
        $speed = isset($avif['speed']) ? (int) $avif['speed'] : $defaults['speed'];

        $metadata = (is_array($convertOptions) && isset($convertOptions['metadata']))
            ? $convertOptions['metadata']
            : 'all';

        $jobs = isset($avif['jobs']) ? (int) $avif['jobs'] : null;

        return [
            'quality' => $quality,
            'speed' => $speed,
            'metadata' => $metadata,
            'jobs' => $jobs,
        ];
    }
}
