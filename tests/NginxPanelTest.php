<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxPanel;
use MagicConvert\NginxRulesNotice;
use MagicConvert\DismissableGlobalMessages;

require_once __DIR__ . '/NginxPanelWpStubs.php';

class NginxPanelTest extends TestCase
{
    protected function setUp(): void
    {
        \MagicConvert\Tests\reset_wp_stub_options();
    }

    public function testResolvesCanonicalKeys(): void
    {
        $this->assertSame('maps', NginxPanel::resolveArtifactKey('maps'));
        $this->assertSame('server', NginxPanel::resolveArtifactKey('server'));
        $this->assertSame('single', NginxPanel::resolveArtifactKey('single'));
    }

    public function testResolvesRequestAliases(): void
    {
        $this->assertSame('server', NginxPanel::resolveArtifactKey('a'));
        $this->assertSame('single', NginxPanel::resolveArtifactKey('b'));
    }

    public function testKeyResolutionIsCaseInsensitiveAndTrimmed(): void
    {
        $this->assertSame('maps', NginxPanel::resolveArtifactKey('  MAPS '));
        $this->assertSame('single', NginxPanel::resolveArtifactKey('Single'));
    }

    public function testRejectsUnknownOrHostileKeys(): void
    {
        $this->assertFalse(NginxPanel::resolveArtifactKey(''));
        $this->assertFalse(NginxPanel::resolveArtifactKey('c'));
        $this->assertFalse(NginxPanel::resolveArtifactKey('../../etc/passwd'));
        $this->assertFalse(NginxPanel::resolveArtifactKey('maps.conf'));
        $this->assertFalse(NginxPanel::resolveArtifactKey(null));
        $this->assertFalse(NginxPanel::resolveArtifactKey(['maps']));
        $this->assertFalse(NginxPanel::resolveArtifactKey(0));
    }

    public function testEveryAliasResolvesToACanonicalKeyThatHasAFilename(): void
    {
        foreach (array_keys(NginxPanel::ALIASES) as $alias) {
            $canonical = NginxPanel::resolveArtifactKey($alias);
            $this->assertNotFalse($canonical, "alias '$alias' must resolve");
            $this->assertArrayHasKey($canonical, NginxPanel::FILENAMES);
        }
    }

    public function testDownloadFilenames(): void
    {
        $this->assertSame('magic-convert-maps.conf', NginxPanel::downloadFilename('maps'));
        $this->assertSame('magic-convert-server.conf', NginxPanel::downloadFilename('server'));
        $this->assertSame('magic-convert.conf', NginxPanel::downloadFilename('single'));
        $this->assertSame('magic-convert.conf', NginxPanel::downloadFilename('nope'));
    }

    public function testGenerateArtifactRejectsUnknownCanonicalKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NginxPanel::generateArtifactFromPaths('definitely-not-a-key', []);
    }

    private function record(string $fp): array
    {
        return ['fingerprint' => $fp, 'generated-at' => '2026-06-12 00:00:00 UTC', 'plugin-version' => '0.1.0'];
    }

    public function testFirstSaveIsNotAChange(): void
    {
        $this->assertFalse(NginxPanel::fingerprintChanged(null, $this->record('abc')));
        $this->assertFalse(NginxPanel::fingerprintChanged([], $this->record('abc')));
        $this->assertFalse(NginxPanel::fingerprintChanged(['generated-at' => 'x'], $this->record('abc')));
    }

    public function testSameFingerprintIsNotAChange(): void
    {
        $this->assertFalse(NginxPanel::fingerprintChanged($this->record('abc'), $this->record('abc')));
    }

    public function testDifferentFingerprintIsAChange(): void
    {
        $this->assertTrue(NginxPanel::fingerprintChanged($this->record('abc'), $this->record('def')));
    }

    public function testEmptyPreviousFingerprintErrsTowardChange(): void
    {
        $this->assertTrue(NginxPanel::fingerprintChanged($this->record(''), $this->record('def')));
    }

    public function testShortFingerprint(): void
    {
        $this->assertSame('0123456789ab', NginxPanel::shortFingerprint('0123456789abcdef0123456789abcdef'));
        $this->assertSame('(none)', NginxPanel::shortFingerprint(''));
    }

    public function testArmDoesNothingWhenNotNginx(): void
    {
        $armed = NginxRulesNotice::arm($this->record('abc'), $this->record('def'), false);
        $this->assertFalse($armed);
        $this->assertNotContains(NginxRulesNotice::MESSAGE_ID, $this->queuedGlobalMessageIds());
    }

    public function testArmDoesNothingWhenFingerprintUnchanged(): void
    {
        $armed = NginxRulesNotice::arm($this->record('abc'), $this->record('abc'), true);
        $this->assertFalse($armed);
        $this->assertNotContains(NginxRulesNotice::MESSAGE_ID, $this->queuedGlobalMessageIds());
    }

    public function testArmDoesNothingOnFirstSaveEvenOnNginx(): void
    {
        $armed = NginxRulesNotice::arm(null, $this->record('abc'), true);
        $this->assertFalse($armed);
        $this->assertNotContains(NginxRulesNotice::MESSAGE_ID, $this->queuedGlobalMessageIds());
    }

    public function testArmQueuesNoticeWhenNginxAndChanged(): void
    {
        $armed = NginxRulesNotice::arm($this->record('abc'), $this->record('def'), true);
        $this->assertTrue($armed);
        $this->assertContains(NginxRulesNotice::MESSAGE_ID, $this->queuedGlobalMessageIds());
    }

    public function testArmReappearsAfterDismissal(): void
    {
        NginxRulesNotice::arm($this->record('abc'), $this->record('def'), true);
        DismissableGlobalMessages::dismissMessage(NginxRulesNotice::MESSAGE_ID);
        $this->assertNotContains(NginxRulesNotice::MESSAGE_ID, $this->queuedGlobalMessageIds());

        $armed = NginxRulesNotice::arm($this->record('def'), $this->record('ghi'), true);
        $this->assertTrue($armed);
        $this->assertContains(NginxRulesNotice::MESSAGE_ID, $this->queuedGlobalMessageIds());
    }

    private function queuedGlobalMessageIds(): array
    {
        return \MagicConvert\State::getState('dismissableGlobalMessageIds', []);
    }
}
