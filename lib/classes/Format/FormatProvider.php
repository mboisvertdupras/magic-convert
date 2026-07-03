<?php

namespace MagicConvert\Format;

interface FormatProvider
{
    public function id(): string;

    public function converterIds(): array;

    public function optionDefaults(): array;

    public function normalizeOptions(array $options): array;

    public function converterEntryFromConfig(array $config, string $converterId): ?array;

    public function memoryReserveBytes(): int;

    public function concurrencyWeight(): int;

    public function encode(string $source, string $destination, array $options, $logger): void;

    public function encodeWith(string $converterId, string $source, string $destination, array $options, $logger): void;

    /**
     * @return array<int, array{id:string, label:string, operational:bool, reason:string}>
     */
    public function selfTest(): array;

    /**
     * @return 'binary'|'isolated'|'in-process'|'none'
     */
    public function memorySafetyMode(): string;
}
