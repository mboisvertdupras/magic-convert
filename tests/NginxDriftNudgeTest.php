<?php

namespace MagicConvert\Tests;

use PHPUnit\Framework\TestCase;
use MagicConvert\NginxDriftNudge;
use MagicConvert\NginxRulesNotice;
use MagicConvert\DismissableGlobalMessages;
use MagicConvert\SelfTestNginx;

require_once __DIR__ . '/NginxPanelWpStubs.php';

/**
 * Tests for the Phase 3.3 drift nudge's no-re-nag behaviour (Check 4: dismiss + same-fingerprint
 * re-check stays dismissed).
 *
 * Two layers:
 *   - The PURE decision core NginxDriftNudge::decideAction (no WP / no I/O).
 *   - An end-to-end State-backed simulation of the daily nudge sequence, driving the SAME
 *     DismissableGlobalMessages / State machinery the production code uses (WordPress stubbed at the
 *     namespace seam — see NginxPanelWpStubs.php), to prove a dismissed nudge does NOT reappear on
 *     the next same-fingerprint check.
 */
class NginxDriftNudgeTest extends TestCase
{
    protected function setUp(): void
    {
        \MagicConvert\Tests\reset_wp_stub_options();
    }

    // --- pure decision core -------------------------------------------------------------------

    public function testStaleNeverNudgedBeforeArms(): void
    {
        $this->assertSame('arm', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_STALE, 'OLD', ''));
    }

    public function testStaleSameInstalledAsAlreadyNudgedIsNoop(): void
    {
        // The exact Check-4 case: same standing drift we already nudged about => stay silent.
        $this->assertSame('noop', NginxDriftNudge::decideAction(SelfTestNginx::DRIFT_STALE, 'OLD', 'OLD'));
    }

    public function testStaleWithDifferentInstalledRearms(): void
    {
        // A genuinely NEW drift state (installed fingerprint changed) re-arms.
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

    // --- end-to-end State-backed sequence (Check 4) -------------------------------------------

    /**
     * Simulate one daily nudge tick: given a drift classification + the installed fingerprint, apply
     * exactly the same arm/resync/noop side effects maybeCheck() applies (minus the throttle and the
     * HTTP probe, which are not pure). This mirrors the switch in maybeCheck().
     */
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
        // Day 1: drift stands, never nudged before -> notice armed.
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-FP');
        $this->assertTrue($this->noticeQueued(), 'first stale check should arm the notice');

        // User dismisses the notice.
        DismissableGlobalMessages::dismissMessage(NginxRulesNotice::MESSAGE_ID);
        $this->assertFalse($this->noticeQueued());

        // Day 2 & 3: throttle expired, nudge re-runs, drift STILL stands with the SAME installed
        // fingerprint (user has not reinstalled). The notice must NOT reappear.
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-FP');
        $this->assertFalse($this->noticeQueued(), 'same standing drift must not re-nag after dismissal');
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-FP');
        $this->assertFalse($this->noticeQueued(), 'same standing drift must still not re-nag');
    }

    public function testNewDriftAfterDismissalReNudges(): void
    {
        // Day 1: arm + dismiss.
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-A');
        DismissableGlobalMessages::dismissMessage(NginxRulesNotice::MESSAGE_ID);
        $this->assertFalse($this->noticeQueued());

        // The installed fingerprint CHANGES (e.g. settings changed again, or different stale rules
        // were installed) -> a genuinely new drift state -> the notice reappears.
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-B');
        $this->assertTrue($this->noticeQueued(), 'a new drift state should re-nudge');
    }

    public function testResyncThenLaterDriftReNudges(): void
    {
        // Arm + dismiss for STALE-A.
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-A');
        DismissableGlobalMessages::dismissMessage(NginxRulesNotice::MESSAGE_ID);

        // User reinstalls -> up to date. Memory is reset.
        $this->tick(SelfTestNginx::DRIFT_UP_TO_DATE, 'CURRENT-FP');
        $this->assertSame('', (string) \MagicConvert\State::getState(NginxDriftNudge::ACKED_DRIFT_KEY, ''));
        $this->assertFalse($this->noticeQueued());

        // Later, settings change and the SAME old fingerprint drifts again -> re-nudges cleanly,
        // because the resync forgot it.
        $this->tick(SelfTestNginx::DRIFT_STALE, 'STALE-A');
        $this->assertTrue($this->noticeQueued(), 'drift after a resync should re-nudge even for a previously-seen fingerprint');
    }
}
