<?php

namespace MagicConvert\Format;

interface FormatProvider
{
    // Laziness invariant (ADR-0001): construction and the six fact methods
    // (id, converterIds, optionDefaults, normalizeOptions, memoryReserveBytes,
    // concurrencyWeight) must never load vendor code or WordPress. They are pure
    // (normalizeOptions is array-in/array-out). Only encode(), encodeWith(),
    // selfTest() and memorySafetyMode() may pull in heavy code.

    public function id(): string;

    public function converterIds(): array;

    public function optionDefaults(): array;

    public function normalizeOptions(array $options): array;

    public function memoryReserveBytes(): int;

    public function concurrencyWeight(): int;

    public function encode(string $source, string $destination, array $options, $logger): void;

    public function encodeWith(string $converterId, string $source, string $destination, array $options, $logger): void;

    public function selfTest(): array;

    public function memorySafetyMode(): string;
}
