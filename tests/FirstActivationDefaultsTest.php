<?php

namespace MagicConvert\Tests;

use MagicConvert\Config;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the platform-aware first-activation overrides
 * (Config::applyFirstActivationPlatformDefaults) used by zero-config setup on activation.
 *
 * The method is pure & WordPress-independent (array in + a bool, array out — no filesystem, no
 * WordPress, no $_SERVER), so it runs standalone here. PluginActivate itself is WP-coupled and is
 * intentionally NOT exercised; only the decision logic it delegates to is unit-tested.
 */
class FirstActivationDefaultsTest extends TestCase
{
    /**
     * A representative fresh-default alter-html section (mirrors Config::getDefaultConfig()):
     * Alter HTML disabled, picture mode, only-for-webps-that-exists OFF.
     *
     * @return array<string,mixed>
     */
    private function defaultConfig(): array
    {
        return [
            'operation-mode' => 'varied-image-responses',
            'alter-html' => [
                'enabled' => false,
                'replacement' => 'picture',
                'hooks' => 'ob',
                'only-for-webp-enabled-browsers' => true,
                'only-for-webps-that-exists' => false,
                'alter-html-add-picturefill-js' => true,
                'hostname-aliases' => [],
            ],
        ];
    }

    // --- Apache / LiteSpeed ---------------------------------------------------

    public function testApacheLeavesConfigUnchanged(): void
    {
        $config = $this->defaultConfig();
        $result = Config::applyFirstActivationPlatformDefaults($config, false);

        // On Apache/LiteSpeed the defaults are already zero-config (htaccess does the serving),
        // so nothing must change.
        $this->assertSame($config, $result);
    }

    // --- nginx ----------------------------------------------------------------

    public function testNginxEnablesAlterHtml(): void
    {
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertTrue($result['alter-html']['enabled']);
    }

    public function testNginxUsesPictureReplacement(): void
    {
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertSame('picture', $result['alter-html']['replacement']);
    }

    public function testNginxForcesOnlyForWebpsThatExist(): void
    {
        // CRITICAL: without nginx rules there is no realizer, so we must only reference converted
        // files that already exist on disk — never emit a reference to a missing file.
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertTrue($result['alter-html']['only-for-webps-that-exists']);
    }

    public function testNginxSetsOnlyForWebpEnabledBrowsersFalse(): void
    {
        // Picture mode handles capability negotiation; matching submit.php's picture-mode choice
        // keeps the value stable across a settings round-trip.
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertFalse($result['alter-html']['only-for-webp-enabled-browsers']);
    }

    public function testNginxDoesNotTouchOperationMode(): void
    {
        // The overrides only adjust alter-html; the operation mode (and thus the fix()/
        // applyOperationMode round-trip behaviour) stays at the default.
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertSame('varied-image-responses', $result['operation-mode']);
    }

    public function testNginxSurvivesMissingAlterHtmlSection(): void
    {
        // Defensive: a config without an alter-html section must not fatal; the section is created.
        $config = ['operation-mode' => 'varied-image-responses'];
        $result = Config::applyFirstActivationPlatformDefaults($config, true);

        $this->assertIsArray($result['alter-html']);
        $this->assertTrue($result['alter-html']['enabled']);
        $this->assertSame('picture', $result['alter-html']['replacement']);
        $this->assertTrue($result['alter-html']['only-for-webps-that-exists']);
        $this->assertFalse($result['alter-html']['only-for-webp-enabled-browsers']);
    }

    /**
     * The nginx overrides must survive the merge fix() performs on the next settings-page visit:
     * fix() does array_replace_recursive($defaultAlterHtml, $config['alter-html']). We simulate that
     * merge here to prove the chosen flags are not clobbered back to defaults.
     */
    public function testNginxOverridesSurviveFixMerge(): void
    {
        $defaultAlterHtml = $this->defaultConfig()['alter-html'];

        $afterActivation = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $merged = array_replace_recursive($defaultAlterHtml, $afterActivation['alter-html']);

        $this->assertTrue($merged['enabled']);
        $this->assertSame('picture', $merged['replacement']);
        $this->assertTrue($merged['only-for-webps-that-exists']);
        $this->assertFalse($merged['only-for-webp-enabled-browsers']);
    }
}
