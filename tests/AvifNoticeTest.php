<?php

namespace MagicConvert\Tests;

use MagicConvert\AvifNotice;
use MagicConvert\Avif\AvifStack;
use MagicConvert\Avif\AbstractAvifConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AvifNotice::shouldShow() — the pure decision behind the persistent
 * "AVIF enabled but no AVIF-capable converter" admin notice (Phase 2.5).
 *
 * The operability decision is delegated to an injected AvifStack (the SAME detection the
 * conversion path uses), so here we drive it with a stack built from a fake converter.
 */
class AvifNoticeTest extends TestCase
{
    /** An AvifStack whose single converter reports the given operability. */
    private function stackThatIs(bool $operational): AvifStack
    {
        $converter = new class($operational) extends AbstractAvifConverter {
            private $op;
            public function __construct($op) { $this->op = $op; }
            public function id() { return 'fake'; }
            public function label() { return 'Fake'; }
            public function isOperational() { return ['operational' => $this->op, 'reason' => $this->op ? '' : 'nope']; }
            public function convert($source, $destination, array $options) {}
        };
        return new AvifStack([$converter]);
    }

    private function avifEnabledConfig(bool $enabled): array
    {
        return ['formats' => ['webp' => ['enabled' => true], 'avif' => ['enabled' => $enabled]]];
    }

    public function testShownWhenAvifEnabledButNoConverterOperational(): void
    {
        $this->assertTrue(
            AvifNotice::shouldShow($this->avifEnabledConfig(true), $this->stackThatIs(false), false)
        );
    }

    public function testHiddenWhenAConverterIsOperational(): void
    {
        $this->assertFalse(
            AvifNotice::shouldShow($this->avifEnabledConfig(true), $this->stackThatIs(true), false)
        );
    }

    public function testHiddenWhenAvifDisabled(): void
    {
        // AVIF off => never relevant, even with no operational converter.
        $this->assertFalse(
            AvifNotice::shouldShow($this->avifEnabledConfig(false), $this->stackThatIs(false), false)
        );
    }

    public function testHiddenWhenAlreadyDismissed(): void
    {
        $this->assertFalse(
            AvifNotice::shouldShow($this->avifEnabledConfig(true), $this->stackThatIs(false), true)
        );
    }
}
