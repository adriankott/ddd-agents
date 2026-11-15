<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionRecord;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\Evals\AgentEvalSkillPreloadSummary;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The skill-preload verdict of a run per owner turn: the decision model's pick against the case's expectation (a hit,
 * a wrong pick, a skip with its reason, not asked, no expectation; `A|B` alternatives; `none`), its sub-skill passes,
 * and the chat model's own first load beside it.
 */
class AgentEvalSkillPreloadSummaryTest extends UnitTestCase
{
    /** @var string[] The expectation per owner turn of {@see conversationOfNineOwnerTurns()} */
    protected const array EXPECTED_SKILLS_BY_TURN = [
        'alphaSkill|gammaSkill',
        'betaSkill',
        'none',
        ' none ',
        'betaSkill',
        'betaSkill',
        'betaSkill',
        '',
        'alphaSkill',
    ];

    public function testEveryOwnerTurnGetsItsVerdictAndTheTotalsCountTheJudgedTurns(): void
    {
        $summary = AgentEvalSkillPreloadSummary::fromConversation($this->conversationOfNineOwnerTurns(), self::EXPECTED_SKILLS_BY_TURN);

        self::assertSame($this->expectedSummaryOfNineOwnerTurns(), $summary->toArray());
    }

    public function testAConversationWithoutDecisionsOrExpectationsJudgesNothing(): void
    {
        $summary = AgentEvalSkillPreloadSummary::fromConversation($this->conversationWithoutDecisions(), []);

        self::assertSame($this->expectedSummaryWithoutDecisions(), $summary->toArray());
    }

    public function testAConversationWithoutAnOwnerTextHasNoTurn(): void
    {
        $conversation = $this->conversation([
            $this->signal(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER),
            $this->toolCall('load_skills', ['skill_names' => ['alphaSkill']]),
        ]);

        self::assertSame(
            ['turns' => [], 'hits' => 0, 'wrongPicks' => 0, 'skips' => 0, 'notAsked' => 0, 'judged' => 0, 'modelFirstLoadHits' => 0],
            AgentEvalSkillPreloadSummary::fromConversation($conversation, ['alphaSkill'])->toArray()
        );
    }

    /**
     * Nine owner turns (a legacy user-role text included; a user-role signal opens none) with the chat model's loads
     * and the decision model's records, one turn per verdict branch.
     */
    protected function conversationOfNineOwnerTurns(): AIConversation
    {
        $conversation = $this->conversation([
            $this->toolCall('load_skills', ['skill_names' => ['gammaSkill']]),           // 0: before the first owner turn
            $this->ownerText(),                                                           // 1: turn 0
            $this->toolCall('load_skills', ['skill_names' => ['alphaSkill', 'betaSkill']]),
            $this->assistantText(),
            $this->ownerText(),                                                           // 4: turn 1
            $this->toolCall('findWidgets', [], ['betaSkill']),
            $this->toolCall('load_skills', ['skill_names' => ['alphaSkill']]),
            $this->ownerText(),                                                           // 7: turn 2
            $this->ownerText(),                                                           // 8: turn 3
            $this->toolCall('findWidgets', [], []),
            $this->ownerText(),                                                           // 10: turn 4
            $this->ownerText(),                                                           // 11: turn 5
            $this->ownerText(),                                                           // 12: turn 6
            $this->signal(AIConversationMessage::ROLE_USER, AIConversationMessage::AUTHOR_USER),
            $this->ownerText(),                                                           // 14: turn 7
            $this->ownerText(null),                                                       // 15: turn 8, a legacy row
        ]);
        $agentWorkingState = new AgentWorkingState();
        $agentWorkingState->decisions = [
            $this->preloadRecord(1, ['pick' => 'alphaSkill', 'applied' => true, 'pickedByRerank' => true, 'pickConfidence' => 0.91, 'pickMargin' => 0.3]),
            $this->preloadRecord(1, ['pick' => 'gammaSkill', 'applied' => false]),
            $this->preloadRecord(1, ['subject' => 'rerank', 'pick' => 'gammaSkill', 'applied' => true]),
            $this->preloadRecord(1, ['unlockedByRootSkillName' => 'alphaSkill', 'pick' => 'alphaSubSkill', 'applied' => true]),
            $this->preloadRecord(1, ['unlockedByRootSkillName' => 'alphaSkill', 'pick' => 'alphaSubSkill', 'applied' => false]),
            $this->preloadRecord(1, ['unlockedByRootSkillName' => 'alphaSkill', 'pick' => 'alphaOtherSubSkill', 'applied' => false]),
            $this->preloadRecord(1, ['unlockedByRootSkillName' => 'alphaSkill', 'pick' => null]),
            $this->preloadRecord(4, ['pick' => 'alphaSkill', 'applied' => true]),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_PLAN_DYNAMICS, 4, ['pick' => 'betaSkill', 'applied' => true]),
            $this->preloadRecord(7, ['pick' => 'betaSkill', 'applied' => true]),
            $this->preloadRecord(8, ['pick' => null, 'applied' => false, 'skipReason' => 'lowConfidence']),
            $this->preloadRecord(10, ['pick' => 'betaSkill', 'applied' => false, 'skipReason' => 'belowMargin']),
            $this->preloadRecord(11, ['pick' => 'gammaSkill', 'applied' => false]),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, 12, null),
            $this->preloadRecord(14, ['pick' => 'alphaSkill', 'applied' => true]),
            $this->preloadRecord(15, ['applied' => false, 'skipReason' => 'quota']),
            $this->decisionRecord(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, null, ['pick' => 'alphaSkill', 'applied' => true]),
        ];
        $conversation->agentWorkingState = $agentWorkingState;
        return $conversation;
    }

    /** Two owner turns without a working state. */
    protected function conversationWithoutDecisions(): AIConversation
    {
        return $this->conversation([
            $this->ownerText(),
            $this->toolCall('load_skills', ['skill_names' => ['alphaSkill']]),
            $this->ownerText(),
        ]);
    }

    /** @return array<string, mixed> the expected summary of this conversation */
    protected function expectedSummaryOfNineOwnerTurns(): array
    {
        return [
            'turns' => [
                ['turn' => 0, 'ownerMessageIndex' => 1, 'expected' => 'alphaSkill|gammaSkill', 'pick' => 'alphaSkill', 'applied' => true, 'pickedByRerank' => true, 'skipReason' => '', 'pickConfidence' => 0.91, 'pickMargin' => 0.3, 'modelFirstLoad' => 'alphaSkill', 'subSkillPicks' => ['alphaSubSkill', 'alphaOtherSubSkill'], 'subSkillsApplied' => ['alphaSubSkill'], 'verdict' => 'hit'],
                ['turn' => 1, 'ownerMessageIndex' => 4, 'expected' => 'betaSkill', 'pick' => 'alphaSkill', 'applied' => true, 'pickedByRerank' => false, 'skipReason' => '', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => 'betaSkill', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'wrongPick'],
                ['turn' => 2, 'ownerMessageIndex' => 7, 'expected' => 'none', 'pick' => 'betaSkill', 'applied' => true, 'pickedByRerank' => false, 'skipReason' => '', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'wrongPick'],
                ['turn' => 3, 'ownerMessageIndex' => 8, 'expected' => 'none', 'pick' => '', 'applied' => false, 'pickedByRerank' => false, 'skipReason' => 'lowConfidence', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'hit'],
                ['turn' => 4, 'ownerMessageIndex' => 10, 'expected' => 'betaSkill', 'pick' => 'betaSkill', 'applied' => false, 'pickedByRerank' => false, 'skipReason' => 'belowMargin', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'skip:belowMargin (pick was right)'],
                ['turn' => 5, 'ownerMessageIndex' => 11, 'expected' => 'betaSkill', 'pick' => 'gammaSkill', 'applied' => false, 'pickedByRerank' => false, 'skipReason' => '', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'skip:unknown (pick gammaSkill)'],
                ['turn' => 6, 'ownerMessageIndex' => 12, 'expected' => 'betaSkill', 'pick' => '', 'applied' => false, 'pickedByRerank' => false, 'skipReason' => '', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'notAsked'],
                ['turn' => 7, 'ownerMessageIndex' => 14, 'expected' => '', 'pick' => 'alphaSkill', 'applied' => true, 'pickedByRerank' => false, 'skipReason' => '', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'noExpectation'],
                ['turn' => 8, 'ownerMessageIndex' => 15, 'expected' => 'alphaSkill', 'pick' => '', 'applied' => false, 'pickedByRerank' => false, 'skipReason' => 'quota', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'skip:quota'],
            ],
            'hits' => 2,
            'wrongPicks' => 2,
            'skips' => 3,
            'notAsked' => 1,
            'judged' => 8,
            'modelFirstLoadHits' => 2,
        ];
    }

    /** @return array<string, mixed> the expected summary of this conversation */
    protected function expectedSummaryWithoutDecisions(): array
    {
        return [
            'turns' => [
                ['turn' => 0, 'ownerMessageIndex' => 0, 'expected' => '', 'pick' => '', 'applied' => false, 'pickedByRerank' => false, 'skipReason' => '', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => 'alphaSkill', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'noExpectation'],
                ['turn' => 1, 'ownerMessageIndex' => 2, 'expected' => '', 'pick' => '', 'applied' => false, 'pickedByRerank' => false, 'skipReason' => '', 'pickConfidence' => null, 'pickMargin' => null, 'modelFirstLoad' => '', 'subSkillPicks' => [], 'subSkillsApplied' => [], 'verdict' => 'noExpectation'],
            ],
            'hits' => 0,
            'wrongPicks' => 0,
            'skips' => 0,
            'notAsked' => 0,
            'judged' => 0,
            'modelFirstLoadHits' => 0,
        ];
    }

    /** @param AIConversationMessage[] $messageList */
    protected function conversation(array $messageList): AIConversation
    {
        $conversation = AIConversation::newInstance();
        $conversation->messages = new AIConversationMessages();
        foreach ($messageList as $index => $message) {
            $message->indexInConversation = $index;
            $conversation->messages->add($message);
        }
        return $conversation;
    }

    protected function ownerText(?string $authorType = AIConversationMessage::AUTHOR_USER): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Please help';
        return $message;
    }

    protected function assistantText(): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'Done';
        return $message;
    }

    protected function signal(string $role, string $authorType): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = $role;
        $message->authorType = $authorType;
        $message->messageType = AIConversationMessage::MESSAGE_SIGNAL;
        $message->message = 'clicked';
        return $message;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param string[] $skillNamesLoadedByTheCall
     */
    protected function toolCall(string $toolName, array $arguments, array $skillNamesLoadedByTheCall = []): AIConversationMessage
    {
        $message = new AIConversationMessage();
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $message->message = '';
        $toolCall = new ToolCall();
        $toolCall->name = $toolName;
        $toolCall->arguments = $arguments;
        $toolCall->status = AIConversationMessage::STATUS_COMPLETED;
        $toolCall->skillNamesLoadedByTheCall = $skillNamesLoadedByTheCall;
        $message->toolCall = $toolCall;
        return $message;
    }

    /** @param array<string, mixed> $outcome */
    protected function preloadRecord(int $ownerMessageIndex, array $outcome): AgentDecisionRecord
    {
        return $this->decisionRecord(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, $ownerMessageIndex, $outcome);
    }

    /** @param array<string, mixed>|null $outcome */
    protected function decisionRecord(string $site, ?int $ownerMessageIndex, ?array $outcome): AgentDecisionRecord
    {
        $decisionRecord = new AgentDecisionRecord();
        $decisionRecord->site = $site;
        $decisionRecord->ownerMessageIndex = $ownerMessageIndex;
        $decisionRecord->outcome = $outcome;
        return $decisionRecord;
    }
}
