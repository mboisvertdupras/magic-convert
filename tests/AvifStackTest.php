<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\Avif\AbstractAvifConverter;
use MagicConvert\Avif\AvifStack;
use MagicConvert\Avif\AvifStackException;

/**
 * Pure-logic tests for the AVIF converter stack (Phase 2.3).
 *
 * These cover the parts that must be correct regardless of what is installed on
 * the machine:
 *   - speed → effort / cavif-speed mappings,
 *   - quality / speed clamps + defaults,
 *   - metadata-strip semantics,
 *   - stack ordering (first operational converter wins; failures fall through),
 *   - aggregate-failure message composition.
 *
 * Real backends are replaced with fakes (FakeAvifConverter), so nothing here
 * depends on GD/Imagick/avifenc being present.
 */
class AvifStackTest extends TestCase
{
    // --- speed → effort / cavif mappings -------------------------------------

    public function testSpeedToVipsEffortIsInverted(): void
    {
        // effort = 9 - speed, clamped to 0..9.
        $this->assertSame(9, AbstractAvifConverter::speedToVipsEffort(0));
        $this->assertSame(3, AbstractAvifConverter::speedToVipsEffort(6)); // default
        $this->assertSame(0, AbstractAvifConverter::speedToVipsEffort(9));
        $this->assertSame(0, AbstractAvifConverter::speedToVipsEffort(10)); // clamps at 0
    }

    public function testSpeedToVipsEffortClampsOutOfRangeInput(): void
    {
        $this->assertSame(9, AbstractAvifConverter::speedToVipsEffort(-5)); // speed clamps to 0 -> effort 9
        $this->assertSame(0, AbstractAvifConverter::speedToVipsEffort(99)); // speed clamps to 10 -> effort 0
    }

    public function testSpeedToCavifSpeedFloorsAtOne(): void
    {
        // cavif rejects 0; our 0 maps up to 1. Direction otherwise identical.
        $this->assertSame(1, AbstractAvifConverter::speedToCavifSpeed(0));
        $this->assertSame(1, AbstractAvifConverter::speedToCavifSpeed(1));
        $this->assertSame(6, AbstractAvifConverter::speedToCavifSpeed(6));
        $this->assertSame(10, AbstractAvifConverter::speedToCavifSpeed(10));
        $this->assertSame(10, AbstractAvifConverter::speedToCavifSpeed(50)); // clamps
    }

    // --- option clamps / defaults (via a probe subclass) ----------------------

    public function testQualityDefaultsTo30AndClamps(): void
    {
        $probe = new OptionProbe();
        $this->assertSame(30, $probe->q([]));            // default
        $this->assertSame(55, $probe->q(['quality' => 55]));
        $this->assertSame(0, $probe->q(['quality' => -10]));
        $this->assertSame(100, $probe->q(['quality' => 9999]));
    }

    public function testSpeedDefaultsTo6AndClamps(): void
    {
        $probe = new OptionProbe();
        $this->assertSame(6, $probe->s([]));             // default
        $this->assertSame(3, $probe->s(['speed' => 3]));
        $this->assertSame(0, $probe->s(['speed' => -1]));
        $this->assertSame(10, $probe->s(['speed' => 42]));
    }

    public function testStripMetadataOnlyOnExactNone(): void
    {
        $probe = new OptionProbe();
        $this->assertTrue($probe->strip(['metadata' => 'none']));
        $this->assertFalse($probe->strip(['metadata' => 'all']));
        $this->assertFalse($probe->strip([]));                 // absent => keep
        $this->assertFalse($probe->strip(['metadata' => 'exif'])); // anything else => keep
    }

    // --- stack ordering -------------------------------------------------------

    public function testFirstOperationalConverterWinsAndOrderIsRespected(): void
    {
        $a = new FakeAvifConverter('a', true, true);   // operational, succeeds
        $b = new FakeAvifConverter('b', true, true);
        $stack = new AvifStack([$a, $b]);

        $result = $stack->convert('/src', '/dst', ['quality' => 30, 'speed' => 6]);

        $this->assertSame('a', $result['converter']);
        $this->assertTrue($a->convertCalled);
        $this->assertFalse($b->convertCalled, 'second converter must not be reached once the first succeeds');
    }

    public function testNonOperationalConvertersAreSkippedWithoutConvertCall(): void
    {
        $a = new FakeAvifConverter('a', false, false, 'extension missing');
        $b = new FakeAvifConverter('b', true, true);
        $stack = new AvifStack([$a, $b]);

        $result = $stack->convert('/src', '/dst', []);

        $this->assertSame('b', $result['converter']);
        $this->assertFalse($a->convertCalled, 'a non-operational converter must never have convert() called');
        $this->assertTrue($b->convertCalled);
    }

    public function testOperationalButFailingConverterFallsThroughToNext(): void
    {
        $a = new FakeAvifConverter('a', true, false, '', 'a blew up');  // operational but convert() throws
        $b = new FakeAvifConverter('b', true, true);
        $stack = new AvifStack([$a, $b]);

        $result = $stack->convert('/src', '/dst', []);

        $this->assertSame('b', $result['converter']);
        $this->assertTrue($a->convertCalled);
        $this->assertTrue($b->convertCalled);
        // The log records the failed attempt AND the eventual success.
        $this->assertStringContainsString('a blew up', $result['log']);
        $this->assertStringContainsString('SUCCESS', $result['log']);
    }

    // --- aggregate-failure message composition --------------------------------

    public function testAllConvertersFailingThrowsAggregateWithEveryReason(): void
    {
        $a = new FakeAvifConverter('imagick', false, false, 'imagick not loaded');
        $b = new FakeAvifConverter('gd', false, false, 'no AVIF support');
        $c = new FakeAvifConverter('avifenc', true, false, '', 'binary crashed');
        $stack = new AvifStack([$a, $b, $c]);

        try {
            $stack->convert('/src', '/dst', []);
            $this->fail('expected AvifStackException');
        } catch (AvifStackException $e) {
            $reasons = $e->getReasons();
            $this->assertSame('imagick not loaded', $reasons['imagick']);
            $this->assertSame('no AVIF support', $reasons['gd']);
            $this->assertSame('binary crashed', $reasons['avifenc']);

            $msg = $e->getMessage();
            $this->assertStringContainsString('imagick: imagick not loaded', $msg);
            $this->assertStringContainsString('gd: no AVIF support', $msg);
            $this->assertStringContainsString('avifenc: binary crashed', $msg);
        }
    }

    public function testComposeFailureMessageWithNoReasons(): void
    {
        $this->assertSame(
            'No AVIF converters are configured.',
            AvifStack::composeFailureMessage([])
        );
    }

    public function testComposeFailureMessageListsEachConverter(): void
    {
        $msg = AvifStack::composeFailureMessage([
            'gd' => 'reason one',
            'vips' => 'reason two',
        ]);
        $this->assertStringContainsString('Tried:', $msg);
        $this->assertStringContainsString('gd: reason one', $msg);
        $this->assertStringContainsString('vips: reason two', $msg);
    }

    // --- self-test surface ----------------------------------------------------

    public function testSelfTestReportsEachConverterRowWithReason(): void
    {
        $a = new FakeAvifConverter('a', true, true);
        $b = new FakeAvifConverter('b', false, false, 'because reasons');
        $stack = new AvifStack([$a, $b]);

        $rows = $stack->selfTest();
        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['operational']);
        $this->assertSame('a', $rows[0]['id']);
        $this->assertFalse($rows[1]['operational']);
        $this->assertSame('because reasons', $rows[1]['reason']);
    }

    public function testIsOperationalTrueWhenAnyConverterUsable(): void
    {
        $stack = new AvifStack([
            new FakeAvifConverter('a', false, false, 'no'),
            new FakeAvifConverter('b', true, true),
        ]);
        $this->assertTrue($stack->isOperational());
    }

    public function testIsOperationalFalseWhenNoneUsable(): void
    {
        $stack = new AvifStack([
            new FakeAvifConverter('a', false, false, 'no'),
            new FakeAvifConverter('b', false, false, 'nope'),
        ]);
        $this->assertFalse($stack->isOperational());
    }

    // --- default stack composition / ordering --------------------------------

    public function testDefaultStackOrderMatchesPriority(): void
    {
        $ids = array_map(
            static fn ($c) => $c->id(),
            AvifStack::defaultConverters()
        );
        $this->assertSame(
            ['imagick', 'vips', 'gd', 'magick-binary', 'avifenc', 'cavif'],
            $ids
        );
    }
}

/**
 * Fake converter for stack-logic tests — no real encoding.
 */
class FakeAvifConverter extends AbstractAvifConverter
{
    public $convertCalled = false;

    public function __construct(
        private string $idStr,
        private bool $operational,
        private bool $succeeds,
        private string $reason = '',
        private string $failMessage = 'fake convert failure'
    ) {
    }

    public function id()
    {
        return $this->idStr;
    }

    public function label()
    {
        return 'Fake ' . $this->idStr;
    }

    public function isOperational()
    {
        return ['operational' => $this->operational, 'reason' => $this->reason];
    }

    public function convert($source, $destination, array $options)
    {
        $this->convertCalled = true;
        if (!$this->succeeds) {
            throw new \Exception($this->failMessage);
        }
        // Pretend to have written a file (no real I/O in unit tests).
    }
}

/**
 * Exposes the protected option helpers of AbstractAvifConverter for direct testing.
 */
class OptionProbe extends AbstractAvifConverter
{
    public function id()
    {
        return 'probe';
    }

    public function label()
    {
        return 'Probe';
    }

    public function isOperational()
    {
        return ['operational' => true, 'reason' => ''];
    }

    public function convert($source, $destination, array $options)
    {
    }

    public function q(array $o): int
    {
        return $this->quality($o);
    }

    public function s(array $o): int
    {
        return $this->speed($o);
    }

    public function strip(array $o): bool
    {
        return $this->stripMetadata($o);
    }
}
