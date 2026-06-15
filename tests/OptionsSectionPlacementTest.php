<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;

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
        $general = file_get_contents($this->optionsDir . '/general/general.inc');

        $firstControlPos = strpos($general, "include_once 'convert-on-upload.inc'");
        $this->assertNotFalse($firstControlPos, "general.inc should include convert-on-upload.inc");

        $guardBeforeControls = strrpos(substr($general, 0, $firstControlPos), "!= 'no-conversion'");
        $this->assertNotFalse($guardBeforeControls, "general.inc should guard the controls with a no-conversion check");
        $this->assertLessThan(
            120,
            $firstControlPos - $guardBeforeControls,
            "the no-conversion guard must immediately precede the control includes so they are hidden in no-conversion mode"
        );
    }
}
