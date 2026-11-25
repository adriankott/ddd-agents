<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Tests\Agents\Domain\AI\Services\Eval\AISkillsServiceWithSubSkillsDouble;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * The ORDER of the turn start (`AgentTurnStartDecisionBatchService::decideTurnStart()`): the order the site inputs are
 * built in (roots pass → sub-skill inputs → effort → plan → language → injection), the effort applied before the batch
 * when its precondition decides without the model, the children built with the batch (the sub-skill requests, then
 * the injection request), the apply order (injection → language → preload roots with its follow-up pass → effort → plan
 * → each sub-skill child with its request event → the primary request event), "nothing to ask" = no request, our own
 * gate's refusal = every site applied with `fallback:refused`, and the isolation of each application (a failed one is
 * skipped, the later sites and the event of every request follow) while a lost claim leaves the method.
 *
 * A row's cells ({@see TurnStartSequenceCells}) are every site-service call, request, card, turn event, breaker request
 * and claim-fenced write, in call order.
 */
class TurnStartSequenceTest extends DecisionSiteOutcomeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $skillsServiceDouble = new AISkillsServiceWithSubSkillsDouble();
        $skillsServiceDouble->subSkillNamesBySkillName = [self::ROOT_SKILL => [self::SUB_SKILL_ADDRESS, self::SUB_SKILL_LINKS]];
        $this->replaceService(AISkillsService::class, $skillsServiceDouble);
    }

    /** @return array<string, array{0: string[], 1: string, 2: string}> sites, outcome, the row's variant */
    public static function sequenceRows(): array
    {
        $allSites = TurnStartDecisionsOutcomeTableTest::ALL_TURN_START_SITES;
        return [
            'every site folded, every request answers' => [$allSites, self::OUTCOME_USABLE, 'plain'],
            'the effort precondition decides without the model, before the batch' => [['replyLanguage', 'reasoningEffortPrecondition', 'planDynamics'], self::OUTCOME_USABLE, 'plain'],
            'only the effort precondition: no request is sent' => [['reasoningEffortPrecondition'], self::OUTCOME_USABLE, 'plain'],
            'nothing to ask: no request, nothing stamped' => [[], self::OUTCOME_USABLE, 'plain'],
            'only the injection check: the breaker is never asked to close' => [['ownerMessageInjection'], self::OUTCOME_USABLE, 'plain'],
            'refused: every input and child is built, every site is applied as refused' => [$allSites, self::OUTCOME_REFUSED, 'plain'],
            'the language application throws: the later sites and every request event follow' => [$allSites, self::OUTCOME_USABLE, 'languageApplyThrows'],
            'the preload application throws: the later sites and every request event follow' => [$allSites, self::OUTCOME_USABLE, 'rootsApplyThrows'],
            'a sub-skill child application throws: its request event is recorded, the turn start goes on' => [$allSites, self::OUTCOME_USABLE, 'loadedSkillApplyThrows'],
            'the roots pick a root with sub-skills: the follow-up pass is a second request after the batch' => [['replyLanguage', 'skillPreload', 'reasoningEffort'], self::OUTCOME_USABLE, 'rootsPick'],
            'the follow-up pass picks a sub-skill without sub-skills: no third pass' => [['skillPreload'], self::OUTCOME_USABLE, 'rootsPickThenSubSkillPick'],
        ];
    }

    /** @param string[] $sites */
    #[DataProvider('sequenceRows')]
    public function testTheTurnStartSequence(array $sites, string $outcome, string $variant): void
    {
        $rowName = (string)$this->dataName();
        $this->scriptOutcome($outcome);
        $turnStartBatchService = $this->turnStartBatchService($sites);
        if ($variant === 'languageApplyThrows') {
            $turnStartBatchService->replyLanguageServiceDouble->applyFailure = new RuntimeException('a failure inside the language application');
        }
        if ($variant === 'rootsApplyThrows') {
            $turnStartBatchService->skillPreloadServiceDouble->applyFailureBySubjectPrefix['roots'] = new RuntimeException('a failure inside the preload application');
        }
        if ($variant === 'loadedSkillApplyThrows') {
            $turnStartBatchService->skillPreloadServiceDouble->applyFailureBySubjectPrefix['loadedSkill:'] = new RuntimeException('a failure inside the sub-skill application');
        }
        if ($variant === 'rootsPick' || $variant === 'rootsPickThenSubSkillPick') {
            $this->decisionModelDouble->answersByLabel['primary'] = ['skill' => strtolower(self::ROOT_SKILL)];
            $turnStartBatchService->skillPreloadServiceDouble->scriptedInputs['pass2:' . self::ROOT_SKILL] = $this->subSkillPassQuestion('pass2');
        }
        if ($variant === 'rootsPickThenSubSkillPick') {
            $this->decisionModelDouble->answersByLabel['subSkill:' . self::ROOT_SKILL] = ['skill' => strtolower(self::SUB_SKILL_ADDRESS)];
        }

        $turnStartBatchService->decideTurnStart(AgentRun::forTurnEntry(), $this->ownerTurnConversation(), null);

        self::assertSame(TurnStartSequenceCells::CELLS[$rowName] ?? ['<not pinned>'], $this->sequenceOfWorldLog(), $rowName);
    }

    public function testALostClaimInsideAnApplicationLeavesTheTurnStart(): void
    {
        $turnStartBatchService = $this->turnStartBatchService(TurnStartDecisionsOutcomeTableTest::ALL_TURN_START_SITES);
        $turnStartBatchService->replyLanguageServiceDouble->applyFailure = new AgentRunClaimLostException('another worker owns this conversation');

        try {
            $turnStartBatchService->decideTurnStart(AgentRun::forTurnEntry(), $this->ownerTurnConversation(), null);
            self::fail('the lost claim must leave the turn start');
        } catch (AgentRunClaimLostException $claimLost) {
            self::assertSame('another worker owns this conversation', $claimLost->getMessage());
        }
        self::assertSame(
            [
                'site apply ownerMessageInjection child=sent',
                'site apply replyLanguage batchDecidedBy=null primary=sent failure=null',
            ],
            array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => str_starts_with($line, 'site apply'))),
            'the injection check and the language site were reached, nothing after the loss'
        );
        self::assertSame([], array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => str_starts_with($line, 'slice ') || str_starts_with($line, 'card ') || str_starts_with($line, 'event '))), 'a lost claim writes no slice and records no event');
    }

    public function testALostClaimInsideASubSkillApplicationLeavesTheTurnStart(): void
    {
        $turnStartBatchService = $this->turnStartBatchService(TurnStartDecisionsOutcomeTableTest::ALL_TURN_START_SITES);
        $turnStartBatchService->skillPreloadServiceDouble->applyFailureBySubjectPrefix['loadedSkill:'] = new AgentRunClaimLostException('another worker owns this conversation');

        try {
            $turnStartBatchService->decideTurnStart(AgentRun::forTurnEntry(), $this->ownerTurnConversation(), null);
            self::fail('the lost claim must leave the turn start');
        } catch (AgentRunClaimLostException $claimLost) {
            self::assertSame('another worker owns this conversation', $claimLost->getMessage());
        }
        self::assertSame([], array_values(array_filter($this->worldLog->lines, static fn (string $line): bool => str_starts_with($line, 'slice ') || str_starts_with($line, 'card ') || str_starts_with($line, 'event request '))), 'a lost claim writes no slice and records no request event');
    }
}
