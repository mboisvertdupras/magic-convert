<?php

namespace MagicConvert\Tests;

use MagicConvert\AvifNotice;
use MagicConvert\Avif\AvifStack;
use MagicConvert\Avif\AbstractAvifConverter;
use PHPUnit\Framework\TestCase;

class AvifNoticeTest extends TestCase
{
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

    public function testUnaffectedByPresenceOfAvifConverterList(): void
    {
        $withList = [
            'formats' => [
                'webp' => ['enabled' => true],
                'avif' => [
                    'enabled' => true,
                    'converters' => [
                        ['converter' => 'imagick', 'deactivated' => true],
                        ['converter' => 'gd'],
                    ],
                ],
            ],
        ];

        $this->assertTrue(AvifNotice::shouldShow($withList, $this->stackThatIs(false), false));
        $this->assertFalse(AvifNotice::shouldShow($withList, $this->stackThatIs(true), false));
    }
}
