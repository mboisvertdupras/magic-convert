<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxDriftNudge;
use MagicConvert\NginxRulesNotice;
use MagicConvert\DismissableGlobalMessages;
use MagicConvert\SelfTestNginx;

require_once __DIR__ . '/NginxPanelWpStubs.php';

class NginxDriftNudgeTest extends TestCase
{
    protected function setUp(): void
    {
        \MagicConvert\Tests\reset_wp_stub_options();
    }

    public function testStaleNeverNudgedBeforeArms(): void
    {
        $this->assertSame('arm', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_STALE, 'OLD', ''));
    }

    public function testStaleSameInstalledAsAlreadyNudgedIsNoop(): void
    {
        $this->assertSame('noop', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_STALE, 'OLD', 'OLD'));
    }

    public function testStaleWithDifferentInstalledRearms(): void
    {
        $this->assertSame('arm', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_STALE, 'NEWER-STALE', 'OLD'));
    }

    public function testUpToDateWhileTrackingResyncs(): void
    {
        $this->assertSame('resync', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_UP_TO_DATE, 'abc', 'abc'));
    }

    public function testUpToDateWhenNotTrackingIsNoop(): void
    {
        $this->assertSame('noop', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_UP_TO_DATE, 'abc', ''));
    }

    public function testAbsentIsAlwaysNoop(): void
    {
        $this->assertSame('noop', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_ABSENT, null, ''));
        $this->assertSame('noop', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_ABSENT, null, 'OLD'));
    }

    private function tick(string $drift, ?string $installed): void
    {
        $alreadyNudged = (string) \MagicConvert\State::getState(NginxDriftNudge::ACKED_DRIFT_KEY, '');
        switch (NginxDriftNudge::decideAction($drift, $installed, $alreadyNudged)) {
            case 'arm':
                DismissableGlobalMessages::addDismissableMessage(NginxRulesNotice::MESSAGE_ID);
                \MagicConvert\State::setState(NginxDriftNudge::ACKED_DRIFT_KEY, (string) $installed);
                break;
            case 'resync':
                \MagicConvert\State::setState(NginxDriftNudge::ACKED_DRIFT_KEY, '');
                NginxRulesNotice::clear();
                break;
        }
    }

    private function noticeQueued(): bool
    {
        return in_array(
            NginxRulesNotice::MESSAGE_ID,
            \MagicConvert\State::getState('dismissableGlobalMessageIds', []),
            true
        );
    }

    public function testDismissThenSameFingerprintRecheckStaysDismissed(): void
    {
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-FP');
        $this->assertTrue($this->noticeQueued(), 'first stale check should arm the notice');

        DismissableGlobalMessages::dismissMessage(NginxRulesNotice::MESSAGE_ID);
        $this->assertFalse($this->noticeQueued());

        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-FP');
        $this->assertFalse($this->noticeQueued(), 'same standing drift must not re-nag after dismissal');
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-FP');
        $this->assertFalse($this->noticeQueued(), 'same standing drift must still not re-nag');
    }

    public function testNewDriftAfterDismissalReNudges(): void
    {
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-A');
        DismissableGlobalMessages::dismissMessage(NginxRulesNotice::MESSAGE_ID);
        $this->assertFalse($this->noticeQueued());

        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-B');
        $this->assertTrue($this->noticeQueued(), 'a new drift state should re-nudge');
    }

    public function testResyncThenLaterDriftReNudges(): void
    {
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-A');
        DismissableGlobalMessages::dismissMessage(NginxRulesNotice::MESSAGE_ID);

        $this->tick(SelfTestNginx::DRIFT_UP_TO_DATE, 'CURRENT-FP');
        $this->assertSame('', (string) \MagicConvert\State::getState(NginxDriftNudge::ACKED_DRIFT_KEY, ''));
        $this->assertFalse($this->noticeQueued());

        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-A');
        $this->assertTrue($this->noticeQueued(), 'drift after a resync should re-nudge even for a previously-seen fingerprint');
    }
}
