<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/** The decision-model settings (the committed config): off by default, sites opt in, mistyped values fall back safely. */
class AgentDecisionModelSettingsTest extends UnitTestCase
{
    public function testDefaultsAreOffWithNoSites(): void
    {
        $settings = AgentDecisionModelSettings::fromValues(null, null, null, null);
        self::assertSame(AgentDecisionModelSettings::MODE_OFF, $settings->mode);
        self::assertSame([], $settings->enabledSites);
        self::assertFalse($settings->isSiteActive(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE));
        self::assertFalse($settings->appliesDecisions());
        self::assertSame(2.0, $settings->timeoutSeconds);
        self::assertSame(60, $settings->breakerSeconds);
    }

    public function testShadowCallsButNeverApplies(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('shadow', 'replyLanguage', '2', '120');
        self::assertTrue($settings->isSiteActive('replyLanguage'));
        self::assertFalse($settings->appliesDecisions());
        self::assertSame(2.0, $settings->timeoutSeconds);
        self::assertSame(120, $settings->breakerSeconds);
    }

    public function testOnAppliesOnlyListedSites(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('ON', ' ReplyLanguage , planTick ', null, null);
        self::assertTrue($settings->appliesDecisions());
        self::assertTrue($settings->isSiteActive('replylanguage'));
        self::assertTrue($settings->isSiteActive('PLANTICK'));
        self::assertFalse($settings->isSiteActive('announceGuard'));
    }

    public function testOnWithoutSitesIsInert(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('on', '', null, null);
        self::assertFalse($settings->isSiteActive(AgentDecisionModelSettings::SITE_REPLY_LANGUAGE));
    }

    public function testGarbageFallsBackToTheSafeDefaults(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('maybe', 'replyLanguage', 'fast', '-5');
        self::assertSame(AgentDecisionModelSettings::MODE_OFF, $settings->mode);
        self::assertFalse($settings->isSiteActive('replyLanguage'));
        self::assertSame(2.0, $settings->timeoutSeconds);
        self::assertSame(60, $settings->breakerSeconds);
    }

    // ── the turn-closing mode and the per-conversation resolution ──

    protected function conversation(?string $turnClosingRow = null, ?bool $killSwitch = null, ?string $skillPreloadRow = null, int $accountId = 1, ?string $effortRow = null, ?string $stallRow = null): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->accountId = $accountId;
        if ($turnClosingRow !== null || $killSwitch !== null || $skillPreloadRow !== null || $effortRow !== null || $stallRow !== null) {
            $runSettings = new RunSettings();
            $runSettings->decisionModelTurnClosing = $turnClosingRow;
            $runSettings->decisionModelOff = $killSwitch;
            $runSettings->decisionModelSkillPreload = $skillPreloadRow;
            $runSettings->decisionModelReasoningEffort = $effortRow;
            $runSettings->decisionModelStallRecovery = $stallRow;
            $conversation->runSettings = $runSettings;
        }
        return $conversation;
    }

    public function testTheThirdTurnClosingModeParsesCaseInsensitiveAndIsReachableUnderShadow(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('on', 'turnClosing:on', null, null, 'CounterThenDecisionModel');
        self::assertSame('counterThenDecisionModel', $settings->turnClosingMode);
        self::assertSame('counterThenDecisionModel', $settings->turnClosingModeForConversation($this->conversation()));
        self::assertSame('decisionModel', $settings->turnClosingModeForConversation($this->conversation('decisionModel')), 'the row still wins');
        self::assertSame('counterThenDecisionModel', AgentDecisionModelSettings::fromValues('on', 'turnClosing:on', null, null, 'deterministicCounter')->turnClosingModeForConversation($this->conversation('counterThenDecisionModel')));
        // under `shadow` only the counter-first mode is reachable (its decision-model call is a SAMPLE on the counter's bounces)
        $shadow = AgentDecisionModelSettings::fromValues('on', 'turnClosing:shadow', null, null, 'counterThenDecisionModel');
        self::assertSame('counterThenDecisionModel', $shadow->turnClosingModeForConversation($this->conversation()));
        self::assertSame('deterministicCounter', AgentDecisionModelSettings::fromValues('on', 'turnClosing:shadow', null, null, 'decisionModel')->turnClosingModeForConversation($this->conversation()), 'decisionModel under shadow stays today\'s sequence');
        self::assertSame('deterministicCounter', $shadow->turnClosingModeForConversation($this->conversation('off')), 'a row off silences the site');
        self::assertNull(AgentDecisionModelSettings::canonicalTurnClosingMode('bogus'));
    }

    public function testTheEffortSiteHasADefaultAPinAndAnOffRowAndTheStallSiteFollowsThePreloadRowRules(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('on', 'reasoningEffort:on,stallRecovery:shadow', null, null, null);
        self::assertFalse(property_exists($settings, 'reasoningEffortDefault'), 'the effort base is the model catalog, never a decision-layer setting (owner 2026-10-01)');
        self::assertSame('on', $settings->siteModeForConversation('reasoningEffort', $this->conversation()));
        self::assertSame('off', $settings->siteModeForConversation('reasoningEffort', $this->conversation(effortRow: 'off')), 'a row off = the configured effort, no decision');
        self::assertSame('on', $settings->siteModeForConversation('reasoningEffort', $this->conversation(effortRow: 'high')), 'a value row is a PIN, never a site mode');
        self::assertSame('high', $settings->reasoningEffortPinForConversation($this->conversation(effortRow: 'high')));
        self::assertNull($settings->reasoningEffortPinForConversation($this->conversation(effortRow: 'off')));
        self::assertNull($settings->reasoningEffortPinForConversation($this->conversation()));
        self::assertSame('off', $settings->siteModeForConversation('reasoningEffort', $this->conversation(killSwitch: true)), 'the kill switch silences the effort site');
        self::assertSame('shadow', $settings->siteModeForConversation('stallRecovery', $this->conversation()));
        self::assertSame('on', $settings->siteModeForConversation('stallRecovery', $this->conversation(stallRow: 'on')), 'a row on lifts shadow');
        self::assertSame('off', $settings->siteModeForConversation('stallRecovery', $this->conversation(stallRow: 'off')));
        self::assertSame('on', AgentDecisionModelSettings::fromValues('on', 'replyLanguage', null, null)->siteModeForConversation('stallRecovery', $this->conversation(stallRow: 'on')), 'an unlisted stall site is off by default — an explicit row switches it on');
        self::assertSame('off', AgentDecisionModelSettings::fromValues('on', 'replyLanguage', null, null)->siteModeForConversation('stallRecovery', $this->conversation()), 'no row: the default');
        self::assertSame(2, AgentDecisionModelSettings::reasoningEffortRank('high'));
        self::assertSame(-1, AgentDecisionModelSettings::reasoningEffortRank('xhigh'));
    }

    public function testTheGuardModeParsesToBesideUnlessReplaceIsSpelledOut(): void
    {
        self::assertSame('deterministicCounter', AgentDecisionModelSettings::fromValues('on', 'turnClosing', null, null, null)->turnClosingMode);
        self::assertSame('deterministicCounter', AgentDecisionModelSettings::fromValues('on', 'turnClosing', null, null, 'decisionModels')->turnClosingMode, 'a typo is today');
        self::assertSame('decisionModel', AgentDecisionModelSettings::fromValues('on', 'turnClosing', null, null, ' DecisionModel ')->turnClosingMode);
    }

    public function testReplaceIsInertUnlessTheTurnClosingSiteIsOn(): void
    {
        $shadow = AgentDecisionModelSettings::fromValues('on', 'turnClosing:shadow', null, null, 'decisionModel');
        self::assertSame('deterministicCounter', $shadow->turnClosingModeForConversation($this->conversation()), 'a turn-closing mode is not a site mode');
        self::assertSame('decisionModel', $shadow->turnClosingModeForConversation($this->conversation('decisionModel')), 'an explicit row naming the mode switches the site on');
        $unlisted = AgentDecisionModelSettings::fromValues('on', 'replyLanguage', null, null, 'decisionModel');
        self::assertSame('deterministicCounter', $unlisted->turnClosingModeForConversation($this->conversation()), 'no row: the unlisted site stays off');
        self::assertSame('decisionModel', $unlisted->turnClosingModeForConversation($this->conversation('decisionModel')), 'an explicit row wins over the default');
        $off = AgentDecisionModelSettings::fromValues('off', 'turnClosing', null, null, 'decisionModel');
        self::assertSame('deterministicCounter', $off->turnClosingModeForConversation($this->conversation('decisionModel')), 'global off wins');
        $on = AgentDecisionModelSettings::fromValues('on', 'turnClosing', null, null, 'decisionModel');
        self::assertSame('decisionModel', $on->turnClosingModeForConversation($this->conversation()));
    }

    public function testTheRowOverridesTheGuardModeAndCanSilenceTheSite(): void
    {
        $envBeside = AgentDecisionModelSettings::fromValues('on', 'turnClosing,skillPreload:shadow,planItemSkillPreload', null, null, 'deterministicCounter');
        self::assertSame('deterministicCounter', $envBeside->turnClosingModeForConversation($this->conversation()));
        self::assertSame('decisionModel', $envBeside->turnClosingModeForConversation($this->conversation('decisionModel')), 'the R6(c) lever: the row selects the arm');
        $envReplace = AgentDecisionModelSettings::fromValues('on', 'turnClosing', null, null, 'decisionModel');
        self::assertSame('deterministicCounter', $envReplace->turnClosingModeForConversation($this->conversation('deterministicCounter')));
        self::assertSame('off', $envReplace->siteModeForConversation('turnClosing', $this->conversation('off')), 'row off silences the site');
        self::assertSame('deterministicCounter', $envReplace->turnClosingModeForConversation($this->conversation('off')), 'no site, no replace');
        self::assertSame('on', $envReplace->siteModeForConversation('turnClosing', $this->conversation('inherit')));
    }

    public function testTheKillSwitchSilencesEverySiteIncludingThePlanItemPreload(): void
    {
        $settings = AgentDecisionModelSettings::fromValues('on', 'replyLanguage,turnClosing,skillPreload:on,planItemSkillPreload', null, null, 'decisionModel');
        $killed = $this->conversation('decisionModel', true, 'on');
        foreach (['replyLanguage', 'turnClosing', 'skillPreload', 'planItemSkillPreload'] as $site) {
            self::assertSame('off', $settings->siteModeForConversation($site, $killed), "$site must be off under the kill switch");
        }
        self::assertSame('deterministicCounter', $settings->turnClosingModeForConversation($killed));
        $alive = $this->conversation('decisionModel', false, 'on');
        self::assertSame('on', $settings->siteModeForConversation('planItemSkillPreload', $alive));
        self::assertSame('decisionModel', $settings->turnClosingModeForConversation($alive));
    }

    /** Precedence: an explicit run setting (row or arm) > the file's site mode > the default; the global off and the kill switch stay absolute. */
    public function testAnExplicitRunSettingWinsOverTheFilesSiteModeInBothDirections(): void
    {
        $fileOff = AgentDecisionModelSettings::fromValues('on', 'replyLanguage,skillPreload:off,stallRecovery:off', null, null, null);
        $fileOn = AgentDecisionModelSettings::fromValues('on', 'skillPreload:on,stallRecovery:on', null, null, null);
        $fileUnlisted = AgentDecisionModelSettings::fromValues('on', 'replyLanguage', null, null, null);

        self::assertSame('on', $fileOff->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'on')), 'row on > file off');
        self::assertSame('on', $fileOff->siteModeForConversation('stallRecovery', $this->conversation(stallRow: 'on')));
        self::assertSame('off', $fileOn->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'off')), 'row off > file on');
        self::assertSame('off', $fileOn->siteModeForConversation('stallRecovery', $this->conversation(stallRow: 'off')));
        self::assertSame('off', $fileOff->siteModeForConversation('skillPreload', $this->conversation()), 'no row: the file');
        self::assertSame('on', $fileUnlisted->siteModeForConversation('skillPreload', $this->conversation()), 'unlisted: the default (on for the preload site)');
        self::assertSame('off', $fileUnlisted->siteModeForConversation('stallRecovery', $this->conversation()), 'unlisted: the default (off)');
        self::assertSame('on', $fileUnlisted->siteModeForConversation('turnClosing', $this->conversation('counterThenDecisionModel')), 'a turn-closing row using the decision model > the default');
        self::assertSame('off', $fileOn->siteModeForConversation('turnClosing', $this->conversation('off')));
        self::assertSame('off', AgentDecisionModelSettings::fromValues('off', 'skillPreload:on', null, null, null)->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'on')), 'the global off is absolute');
        self::assertSame('off', $fileOff->siteModeForConversation('stallRecovery', $this->conversation(killSwitch: true, stallRow: 'on')), 'the kill switch is absolute');
    }

    public function testTheSkillPreloadRowKeepsPlan74sRulesInsideTheOneHelper(): void
    {
        $shadow = AgentDecisionModelSettings::fromValues('on', 'skillPreload:shadow', null, null, null);
        self::assertSame('shadow', $shadow->siteModeForConversation('skillPreload', $this->conversation()));
        self::assertSame('on', $shadow->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'on')), 'plan 74: a row on lifts shadow');
        self::assertSame('off', $shadow->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'off')));
        // the skill-preload sites are ON by default when unlisted — the veto is an explicit `:off` or the global off
        $unlisted = AgentDecisionModelSettings::fromValues('on', 'replyLanguage', null, null, null);
        self::assertSame('on', $unlisted->siteModeForConversation('skillPreload', $this->conversation()), 'unlisted = the default-on site runs on');
        self::assertSame('on', $unlisted->siteMode('planItemSkillPreload'));
        self::assertSame('off', $unlisted->siteMode('reasoningEffort'), 'the other sites stay off when unlisted');
        $off = AgentDecisionModelSettings::fromValues('on', 'replyLanguage,skillPreload:off', null, null, null);
        self::assertSame('on', $off->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'on')), 'an explicit row wins over the file\'s off');
        self::assertSame('off', $off->siteModeForConversation('skillPreload', $this->conversation()), 'no row: the file\'s off');
        self::assertSame('off', AgentDecisionModelSettings::fromValues('off', 'skillPreload:on', null, null, null)->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'on')), 'the global off stays the master switch');
        self::assertSame('off', $off->siteModeForConversation('skillPreload', $this->conversation(skillPreloadRow: 'on', killSwitch: true)), 'the kill switch still silences the site');
        $globalOff = AgentDecisionModelSettings::fromValues('off', 'skillPreload:on', null, null, null);
        self::assertSame('off', $globalOff->siteMode('skillPreload'), 'the global off vetoes the default-on sites too');
        $cohort = AgentDecisionModelSettings::fromValues('on', 'skillPreload:cohort', null, null, null);
        foreach ([1, 2, 3] as $accountId) {
            self::assertSame($cohort->resolveSkillPreloadModeForAccountId($accountId), $cohort->siteModeForConversation('skillPreload', $this->conversation(accountId: $accountId)));
        }
    }
}
