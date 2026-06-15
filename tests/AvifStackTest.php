<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\Avif\AbstractAvifConverter;
use MagicConvert\Avif\AvifStack;
use MagicConvert\Avif\AvifStackException;

class AvifStackTest extends TestCase
{
    public function testSpeedToVipsEffortIsInverted(): void
    {
        $this->assertSame(9, AbstractAvifConverter::speedToVipsEffort(0));
        $this->assertSame(3, AbstractAvifConverter::speedToVipsEffort(6));
        $this->assertSame(0, AbstractAvifConverter::speedToVipsEffort(9));
        $this->assertSame(0, AbstractAvifConverter::speedToVipsEffort(10));
    }

    public function testSpeedToVipsEffortClampsOutOfRangeInput(): void
    {
        $this->assertSame(9, AbstractAvifConverter::speedToVipsEffort(-5));
        $this->assertSame(0, AbstractAvifConverter::speedToVipsEffort(99));
    }

    public function testSpeedToCavifSpeedFloorsAtOne(): void
    {
        $this->assertSame(1, AbstractAvifConverter::speedToCavifSpeed(0));
        $this->assertSame(1, AbstractAvifConverter::speedToCavifSpeed(1));
        $this->assertSame(6, AbstractAvifConverter::speedToCavifSpeed(6));
        $this->assertSame(10, AbstractAvifConverter::speedToCavifSpeed(10));
        $this->assertSame(10, AbstractAvifConverter::speedToCavifSpeed(50));
    }

    public function testQualityDefaultsTo30AndClamps(): void
    {
        $probe = new OptionProbe();
        $this->assertSame(30, $probe->q([]));
        $this->assertSame(55, $probe->q(['quality' => 55]));
        $this->assertSame(0, $probe->q(['quality' => -10]));
        $this->assertSame(100, $probe->q(['quality' => 9999]));
    }

    public function testSpeedDefaultsTo6AndClamps(): void
    {
        $probe = new OptionProbe();
        $this->assertSame(6, $probe->s([]));
        $this->assertSame(3, $probe->s(['speed' => 3]));
        $this->assertSame(0, $probe->s(['speed' => -1]));
        $this->assertSame(10, $probe->s(['speed' => 42]));
    }

    public function testStripMetadataOnlyOnExactNone(): void
    {
        $probe = new OptionProbe();
        $this->assertTrue($probe->strip(['metadata' => 'none']));
        $this->assertFalse($probe->strip(['metadata' => 'all']));
        $this->assertFalse($probe->strip([]));
        $this->assertFalse($probe->strip(['metadata' => 'exif']));
    }

    public function testFirstOperationalConverterWinsAndOrderIsRespected(): void
    {
        $a = new FakeAvifConverter('a', true, true);
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
        $a = new FakeAvifConverter('a', true, false, '', 'a blew up');
        $b = new FakeAvifConverter('b', true, true);
        $stack = new AvifStack([$a, $b]);

        $result = $stack->convert('/src', '/dst', []);

        $this->assertSame('b', $result['converter']);
        $this->assertTrue($a->convertCalled);
        $this->assertTrue($b->convertCalled);
        $this->assertStringContainsString('a blew up', $result['log']);
        $this->assertStringContainsString('SUCCESS', $result['log']);
    }

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

    public function testDefaultConverterIdsMatchPriorityOrder(): void
    {
        $this->assertSame(
            ['imagick', 'vips', 'gd', 'magick-binary', 'avifenc', 'cavif'],
            AvifStack::defaultConverterIds()
        );
        $this->assertSame(
            array_map(static fn ($c) => $c->id(), AvifStack::defaultConverters()),
            AvifStack::defaultConverterIds()
        );
    }

    private static function idsOf(AvifStack $stack): array
    {
        return array_map(static fn ($c) => $c->id(), $stack->converters());
    }

    public function testFromConverterListOrdersAndFiltersByConfig(): void
    {
        $stack = AvifStack::fromConverterList([
            ['converter' => 'avifenc'],
            ['converter' => 'imagick', 'deactivated' => true],
            ['converter' => 'gd'],
        ]);
        $this->assertSame(['avifenc', 'gd'], self::idsOf($stack));
    }

    public function testFromConverterListSkipsUnknownIdsButKeepsValidOnes(): void
    {
        $stack = AvifStack::fromConverterList([
            ['converter' => 'imagick'],
            ['converter' => 'bogus'],
            ['converter' => 'cavif'],
        ]);
        $this->assertSame(['imagick', 'cavif'], self::idsOf($stack));
    }

    public function testFromConverterListCollapsesDuplicateIdsToFirst(): void
    {
        $stack = AvifStack::fromConverterList([
            ['converter' => 'vips'],
            ['converter' => 'gd'],
            ['converter' => 'vips'],
        ]);
        $this->assertSame(['vips', 'gd'], self::idsOf($stack));
    }

    public function testFromConverterListFallsBackToFullStackWhenMissingOrMalformed(): void
    {
        $defaults = AvifStack::defaultConverterIds();

        $this->assertSame($defaults, self::idsOf(AvifStack::fromConverterList([])));
        $this->assertSame($defaults, self::idsOf(AvifStack::fromConverterList(null)));
        $this->assertSame($defaults, self::idsOf(AvifStack::fromConverterList('nonsense')));

        $this->assertSame($defaults, self::idsOf(AvifStack::fromConverterList([
            ['converter' => 'bogus'],
            ['nope' => true],
        ])));
    }

    public function testFromConverterListHonoursAllDeactivatedAsEmptyStack(): void
    {
        $stack = AvifStack::fromConverterList(
            array_map(
                static fn ($id) => ['converter' => $id, 'deactivated' => true],
                AvifStack::defaultConverterIds()
            )
        );
        $this->assertSame([], self::idsOf($stack));
        $this->assertFalse($stack->isOperational());

        try {
            $stack->convert('/src', '/dst', []);
            $this->fail('expected AvifStackException for an empty stack');
        } catch (AvifStackException $e) {
            $this->assertStringContainsString('No AVIF converters are configured', $e->getMessage());
        }
    }

    public function testFromConverterListBuildsRealConvertersWithExpectedClasses(): void
    {
        $stack = AvifStack::fromConverterList([['converter' => 'gd']]);
        $converters = $stack->converters();
        $this->assertCount(1, $converters);
        $this->assertInstanceOf(\MagicConvert\Avif\GdAvif::class, $converters[0]);
    }

    public function testEveryDefaultIdResolvesToANonNullConverter(): void
    {
        $stack = AvifStack::fromConverterList(array_map(
            static fn ($id) => ['converter' => $id],
            AvifStack::defaultConverterIds()
        ));
        $converters = $stack->converters();
        $this->assertCount(count(AvifStack::defaultConverterIds()), $converters);
        foreach ($converters as $c) {
            $this->assertInstanceOf(AbstractAvifConverter::class, $c);
        }
        $this->assertSame(
            AvifStack::defaultConverterIds(),
            array_map(static fn ($c) => $c->id(), $converters)
        );
    }

    public function testOrderPreferringOutOfProcessMovesBinariesFirstStably(): void
    {
        $a = new FakeAvifConverter('a', true, true, '', 'x', false);
        $b = new FakeAvifConverter('b', true, true, '', 'x', true);
        $c = new FakeAvifConverter('c', true, true, '', 'x', false);
        $d = new FakeAvifConverter('d', true, true, '', 'x', true);

        $ordered = AvifStack::orderPreferringOutOfProcess([$a, $b, $c, $d]);
        $this->assertSame(
            ['b', 'd', 'a', 'c'],
            array_map(static fn ($conv) => $conv->id(), $ordered)
        );
    }

    public function testOrderPreferringOutOfProcessIsNoOpWhenAllInProcess(): void
    {
        $a = new FakeAvifConverter('a', true, true);
        $b = new FakeAvifConverter('b', true, true);
        $ordered = AvifStack::orderPreferringOutOfProcess([$a, $b]);
        $this->assertSame(['a', 'b'], array_map(static fn ($conv) => $conv->id(), $ordered));
    }

    public function testConvertPrefersOutOfProcessOverAnEarlierInProcessConverter(): void
    {
        $inProcess = new FakeAvifConverter('gd', true, true, '', 'x', false);
        $outOfProcess = new FakeAvifConverter('avifenc', true, true, '', 'x', true);
        $stack = new AvifStack([$inProcess, $outOfProcess]);

        $result = $stack->convert('/src', '/dst', []);

        $this->assertSame('avifenc', $result['converter']);
        $this->assertTrue($outOfProcess->convertCalled);
        $this->assertFalse($inProcess->convertCalled, 'the leaky in-process encoder must not run when a binary one works');
    }

    public function testConvertFallsBackToInProcessWhenOutOfProcessFails(): void
    {
        $inProcess = new FakeAvifConverter('gd', true, true, '', 'x', false);
        $outOfProcess = new FakeAvifConverter('avifenc', true, false, '', 'binary blew up', true);
        $stack = new AvifStack([$inProcess, $outOfProcess]);

        $result = $stack->convert('/src', '/dst', []);

        $this->assertSame('gd', $result['converter']);
        $this->assertTrue($outOfProcess->convertCalled);
        $this->assertTrue($inProcess->convertCalled);
    }

    public function testConvertersViewKeepsConfiguredOrderUnchanged(): void
    {
        $inProcess = new FakeAvifConverter('gd', true, true, '', 'x', false);
        $outOfProcess = new FakeAvifConverter('avifenc', true, true, '', 'x', true);
        $stack = new AvifStack([$inProcess, $outOfProcess]);

        $this->assertSame(
            ['gd', 'avifenc'],
            array_map(static fn ($conv) => $conv->id(), $stack->converters())
        );
    }

    public function testRealConvertersDeclareMemoryReclaimCorrectly(): void
    {
        $this->assertFalse((new \MagicConvert\Avif\GdAvif())->reclaimsMemoryOnExit());
        $this->assertFalse((new \MagicConvert\Avif\ImagickAvif())->reclaimsMemoryOnExit());
        $this->assertFalse((new \MagicConvert\Avif\VipsAvif())->reclaimsMemoryOnExit());
        $this->assertTrue((new \MagicConvert\Avif\AvifEncBinary())->reclaimsMemoryOnExit());
        $this->assertTrue((new \MagicConvert\Avif\MagickBinaryAvif())->reclaimsMemoryOnExit());
        $this->assertTrue((new \MagicConvert\Avif\CavifBinary())->reclaimsMemoryOnExit());
    }
}

class FakeAvifConverter extends AbstractAvifConverter
{
    public $convertCalled = false;

    public function __construct(
        private string $idStr,
        private bool $operational,
        private bool $succeeds,
        private string $reason = '',
        private string $failMessage = 'fake convert failure',
        private bool $outOfProcess = false
    ) {
    }

    public function id()
    {
        return $this->idStr;
    }

    public function reclaimsMemoryOnExit()
    {
        return $this->outOfProcess;
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
    }
}

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
