<?php

namespace MagicConvert\Format;

interface FormatProvider
{
    // Laziness invariant (ADR-0001): construction and the fact methods
    // (id, converterIds, optionDefaults, memoryReserveBytes, concurrencyWeight,
    // normalizeOptions, converterEntryFromConfig) must never load vendor code or
    // WordPress. They are pure (array-in/array-out). Only encode(), encodeWith(),
    // selfTest() and memorySafetyMode() may pull in heavy code.

    public function id(): string;

    public function converterIds(): array;

    public function optionDefaults(): array;

    public function normalizeOptions(array $options): array;

    public function converterEntryFromConfig(array $config, string $converterId): ?array;

    public function memoryReserveBytes(): int;

    public function concurrencyWeight(): int;

    public function encode(string $source, string $destination, array $options, $logger): void;

    public function encodeWith(string $converterId, string $source, string $destination, array $options, $logger): void;

    // selfTest() returns config-independent environment capability rows
    // array<int, array{id:string, label:string, operational:bool, reason:string}>,
    // or [] when the format has no config-independent probe (status then comes
    // from the config-aware test-run path). memorySafetyMode() returns one of
    // 'binary'|'isolated'|'in-process'|'none'.
    public function selfTest(): array;

    public function memorySafetyMode(): string;
}
