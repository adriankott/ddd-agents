<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionContextSettings;
use DDD\Domain\AI\Repo\Argus\Conversations\Decisions\ArgusAgentTurnStartDecision;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every turn-start site reads the END of the assistant text before the owner message (its question and offer stand in
 * its last sentences, and a short "ja" refers to exactly that) and the START of the owner message; each cut is marked.
 * The effort, plan-dynamics, direct-widget and turn-start-line sites read the start of the assistant text before, so a
 * long reply with its question at the end reached them without the question.
 */
class ArgusAgentTurnStartDecisionTextCutsTest extends TestCase
{
    protected function tearDown(): void
    {
        AgentDecisionContextSettings::useForCurrentProcess(null);
        parent::tearDown();
    }

    public function testTheEffortAndPlanSitesReadTheEndOfTheAssistantTextAndTheCutIsMarked(): void
    {
        $assistantText = str_repeat('Hier ist der Entwurf der Antwort. ', 30) . 'Soll ich ihn so senden?';
        $ownerMessage = 'Ja. ' . str_repeat('Und bitte danach noch die Fahrten prüfen. ', 30);
        foreach (['effort', 'plan'] as $site) {
            $decision = (new ReflectionClass(ArgusAgentTurnStartDecision::class))->newInstanceWithoutConstructor();
            if ($site === 'effort') {
                $decision->effortSiteRequested = true;
                $decision->effortAssistantTextBefore = $assistantText;
                $decision->effortOwnerMessage = $ownerMessage;
            } else {
                $decision->planDynamicsSiteRequested = true;
                $decision->planAssistantTextBefore = $assistantText;
                $decision->planOwnerMessage = $ownerMessage;
            }

            $state = $decision->buildState();

            self::assertStringEndsWith('Soll ich ihn so senden?', $state[ArgusAgentTurnStartDecision::STATE_ASSISTANT_TEXT], $site . ' reads the question');
            self::assertMatchesRegularExpression('/^\[… \d+ characters cut at the start\] /', $state[ArgusAgentTurnStartDecision::STATE_ASSISTANT_TEXT]);
            self::assertStringStartsWith('Ja. ', $state[ArgusAgentTurnStartDecision::STATE_OWNER_MESSAGE]);
            self::assertMatchesRegularExpression('/ \[… \d+ characters cut at the end\]$/', $state[ArgusAgentTurnStartDecision::STATE_OWNER_MESSAGE]);
        }
    }

    public function testTheLimitsComeFromTheSettings(): void
    {
        $agentDecisionContextSettings = new AgentDecisionContextSettings();
        $agentDecisionContextSettings->turnStartAssistantTextCharacters = 2000;
        AgentDecisionContextSettings::useForCurrentProcess($agentDecisionContextSettings);
        $decision = (new ReflectionClass(ArgusAgentTurnStartDecision::class))->newInstanceWithoutConstructor();
        $decision->effortSiteRequested = true;
        $decision->effortAssistantTextBefore = str_repeat('a', 1500);
        $decision->effortOwnerMessage = 'Ja.';

        self::assertSame(str_repeat('a', 1500), $decision->buildState()[ArgusAgentTurnStartDecision::STATE_ASSISTANT_TEXT], 'within the limit nothing is cut');
    }
}
