<?php

namespace MagicConvert\Format;

use MagicConvert\Avif\AvifStack;
use MagicConvert\ConcurrencyAdvisor;

class AvifProvider implements FormatProvider
{
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

    public function memoryReserveBytes(): int
    {
        return ConcurrencyAdvisor::reserveBytesForFormat('avif');
    }

    public function concurrencyWeight(): int
    {
        return 2;
    }

    public function encode(string $source, string $destination, array $options, $logger): void
    {
        $avifOptions = self::deriveAvifOptions($options);
        $logger->logLn('AVIF conversion (quality=' . $avifOptions['quality']
            . ', speed=' . $avifOptions['speed']
            . ', metadata=' . $avifOptions['metadata'] . ')');
        $logger->logLn('');

        $avifConverterList = (isset($options['avif']['converters']) && is_array($options['avif']['converters']))
            ? $options['avif']['converters']
            : [];
        $stack = AvifStack::fromConverterList($avifConverterList);
        $result = $stack->convert($source, $destination, $avifOptions);

        $logger->logLn($result['log']);
        $logger->logLn('');
        $logger->logLn('Converted with: ' . $result['converter']);
    }

    public function encodeWith(string $converterId, string $source, string $destination, array $options, $logger): void
    {
        $avifOptions = self::deriveAvifOptions($options);
        $logger->logLn('AVIF conversion (quality=' . $avifOptions['quality']
            . ', speed=' . $avifOptions['speed']
            . ', metadata=' . $avifOptions['metadata'] . ')');
        $logger->logLn('');

        $stack = AvifStack::fromConverterList([['converter' => $converterId]]);
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
    private static function deriveAvifOptions($convertOptions): array
    {
        $avif = (is_array($convertOptions) && isset($convertOptions['avif']) && is_array($convertOptions['avif']))
            ? $convertOptions['avif']
            : [];

        $quality = isset($avif['quality']) ? (int) $avif['quality'] : 30;
        $speed = isset($avif['speed']) ? (int) $avif['speed'] : 6;

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
