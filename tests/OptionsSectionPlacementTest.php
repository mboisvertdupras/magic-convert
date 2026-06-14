<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structural regression guard for the placement of the cross-format behaviour controls
 * ("Convert on upload", "Enable logging", "Bulk convert") on the settings page.
 *
 * These three controls used to live in the WebP "Conversion" fieldset, which wrongly
 * implied they were WebP-specific. They are not: convert-on-upload generates EVERY enabled
 * format, bulk convert iterates them, and logging is written per-format. They were moved
 * into the "General" fieldset so the UI reflects that they govern all formats.
 *
 * This test pins that arrangement so an accidental revert (moving them back, or dropping
 * them entirely) fails loudly. It is pure file inspection — no WordPress needed.
 */
class OptionsSectionPlacementTest extends TestCase
{
    /** @var string */
    private $optionsDir;

    /** @var string[] */
    private $controlFiles = ['convert-on-upload.inc', 'logging.inc', 'bulk-convert.inc'];

    protected function setUp(): void
    {
        $this->optionsDir = MAGIC_CONVERT_TESTS_ROOT . '/lib/options/options';
    }

    public function testControlFilesLiveUnderGeneralNotConversionOptions(): void
    {
        foreach ($this->controlFiles as $file) {
            $this->assertFileExists(
                $this->optionsDir . '/general/' . $file,
                "$file should live in the general/ section directory"
            );
            $this->assertFileDoesNotExist(
                $this->optionsDir . '/conversion-options/' . $file,
                "$file should no longer live in the WebP conversion-options/ directory"
            );
        }
    }

    public function testGeneralSectionIncludesAllThreeControls(): void
    {
        $general = file_get_contents($this->optionsDir . '/general/general.inc');
        foreach ($this->controlFiles as $file) {
            $this->assertStringContainsString(
                "include_once '" . $file . "'",
                $general,
                "general.inc should include $file"
            );
        }
    }

    public function testConversionSectionNoLongerIncludesTheControls(): void
    {
        $conversion = file_get_contents($this->optionsDir . '/conversion-options/conversion-options.inc');
        foreach ($this->controlFiles as $file) {
            $this->assertStringNotContainsString(
                $file,
                $conversion,
                "conversion-options.inc should no longer reference $file"
            );
        }
    }

    public function testControlsAreHiddenInNoConversionMode(): void
    {
        // The controls are meaningless without conversion, so they must sit behind the
        // operation-mode != 'no-conversion' guard (preserving their pre-move visibility).
        $general = file_get_contents($this->optionsDir . '/general/general.inc');

        $firstControlPos = strpos($general, "include_once 'convert-on-upload.inc'");
        $this->assertNotFalse($firstControlPos, "general.inc should include convert-on-upload.inc");

        // The guard that actually wraps the controls is the LAST no-conversion guard appearing
        // before the first control include — and it must sit immediately before it (one line),
        // proving the include is inside that guarded block rather than merely after some earlier,
        // unrelated guard (e.g. the one around scope.inc).
        $guardBeforeControls = strrpos(substr($general, 0, $firstControlPos), "!= 'no-conversion'");
        $this->assertNotFalse($guardBeforeControls, "general.inc should guard the controls with a no-conversion check");
        $this->assertLessThan(
            120,
            $firstControlPos - $guardBeforeControls,
            "the no-conversion guard must immediately precede the control includes so they are hidden in no-conversion mode"
        );
    }
}
