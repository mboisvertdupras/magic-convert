<?php

namespace MagicConvert\Tests;

use MagicConvert\Config;
use PHPUnit\Framework\TestCase;

class FirstActivationDefaultsTest extends TestCase
{
    /**
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

    public function testApacheLeavesConfigUnchanged(): void
    {
        $config = $this->defaultConfig();
        $result = Config::applyFirstActivationPlatformDefaults($config, false);

        $this->assertSame($config, $result);
    }

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
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertTrue($result['alter-html']['only-for-webps-that-exists']);
    }

    public function testNginxSetsOnlyForWebpEnabledBrowsersFalse(): void
    {
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertFalse($result['alter-html']['only-for-webp-enabled-browsers']);
    }

    public function testNginxDoesNotTouchOperationMode(): void
    {
        $result = Config::applyFirstActivationPlatformDefaults($this->defaultConfig(), true);
        $this->assertSame('varied-image-responses', $result['operation-mode']);
    }

    public function testNginxSurvivesMissingAlterHtmlSection(): void
    {
        $config = ['operation-mode' => 'varied-image-responses'];
        $result = Config::applyFirstActivationPlatformDefaults($config, true);

        $this->assertIsArray($result['alter-html']);
        $this->assertTrue($result['alter-html']['enabled']);
        $this->assertSame('picture', $result['alter-html']['replacement']);
        $this->assertTrue($result['alter-html']['only-for-webps-that-exists']);
        $this->assertFalse($result['alter-html']['only-for-webp-enabled-browsers']);
    }

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
