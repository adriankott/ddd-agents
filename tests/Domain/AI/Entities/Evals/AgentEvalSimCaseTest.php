<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\CompactionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * One judged case: the generic keys read through typed accessors, every other key kept as it was, nothing refused when
 * the case is built, and the three settings blocks applied to the conversation — run and retention settings merged
 * into what the conversation carries, compaction settings built fresh.
 */
class AgentEvalSimCaseTest extends UnitTestCase
{
    /**
     * A judged case with scripted follow-ups, its settings blocks and an application's own key.
     *
     * @return array<string, mixed>
     */
    protected static function buildCaseFields(): array
    {
        return [
            'goal' => 'Find out how the last week went.',
            'openingPrompt' => 'How did last week go?',
            'userFacts' => 'You run the shop alone.',
            'successRubric' => 'PASS when the answer names the week\'s figures.',
            'maxTurns' => '3',
            'followUpPrompts' => ['  and the week before?  ', '', '   ', 0],
            'followUpIdleMinutes' => ['30', 5],
            'purposeName' => 'Schedule.Run',
            'reportChannel' => 'EMAIL',
            'scheduledRun' => 1,
            'compactionParamsText' => 'segmentMessages=4, tierFanIn=2',
            'localFixture' => ['place' => 'a market town'],
            'runSettings' => ['maxStepsPerTurn' => '12', 'maxTurnCostUsd' => '0.5'],
            'contextRetentionSettings' => ['storeRequestSnapshot' => 1, 'maxToolCallImagesOnWire' => '2'],
            'compactionSettings' => ['enabled' => 1, 'triggerTokens' => '800', 'segmentMessages' => 4, 'tierFanIn' => 2, 'aiModelName' => 'summary-model'],
        ];
    }

    public function testTheGenericKeysAreReadAndEveryOtherKeyIsKept(): void
    {
        $caseFields = static::buildCaseFields();
        $agentEvalSimCase = AgentEvalSimCase::fromArray($caseFields);

        self::assertSame($caseFields, $agentEvalSimCase->toArray());
        self::assertSame('Find out how the last week went.', $agentEvalSimCase->getGoal());
        self::assertSame('How did last week go?', $agentEvalSimCase->getOpeningPrompt());
        self::assertSame('You run the shop alone.', $agentEvalSimCase->getOwnerFacts());
        self::assertSame('PASS when the answer names the week\'s figures.', $agentEvalSimCase->getSuccessRubric());
        self::assertSame(3, $agentEvalSimCase->resolveMaxTurns(6));
        self::assertSame(['and the week before?', '0'], $agentEvalSimCase->getFollowUpPrompts());
        self::assertSame([30, 5], $agentEvalSimCase->getFollowUpIdleMinutes());
        self::assertSame('Schedule.Run', $agentEvalSimCase->getPurposeName());
        self::assertSame('EMAIL', $agentEvalSimCase->getReportChannel());
        self::assertTrue($agentEvalSimCase->isScheduledRun());
        self::assertSame('segmentMessages=4, tierFanIn=2', $agentEvalSimCase->getCompactionParamsText());
        self::assertSame(['place' => 'a market town'], $agentEvalSimCase->getApplicationValue('localFixture'));
        self::assertTrue($agentEvalSimCase->hasKey('localFixture'));
        self::assertNull($agentEvalSimCase->getApplicationValue('missing'));
    }

    public function testAnEmptyCaseReadsAsEmptyAndRunsOnTheDefaults(): void
    {
        $agentEvalSimCase = AgentEvalSimCase::fromArray([]);

        self::assertSame('', $agentEvalSimCase->getGoal());
        self::assertSame('', $agentEvalSimCase->getOpeningPrompt());
        self::assertSame('', $agentEvalSimCase->getOwnerFacts());
        self::assertSame('', $agentEvalSimCase->getSuccessRubric());
        self::assertSame('', $agentEvalSimCase->getCompactionParamsText());
        self::assertSame(6, $agentEvalSimCase->resolveMaxTurns(6));
        self::assertSame([], $agentEvalSimCase->getFollowUpPrompts());
        self::assertSame([], $agentEvalSimCase->getFollowUpIdleMinutes());
        self::assertNull($agentEvalSimCase->getPurposeName());
        self::assertNull($agentEvalSimCase->getReportChannel());
        self::assertFalse($agentEvalSimCase->isScheduledRun());
    }

    public function testATurnLimitBelowOneRunsOneTurnAndAnEmptyPurposeNameStaysAText(): void
    {
        $agentEvalSimCase = AgentEvalSimCase::fromArray(['maxTurns' => 0, 'purposeName' => '', 'reportChannel' => '']);

        self::assertSame(1, $agentEvalSimCase->resolveMaxTurns(6));
        self::assertSame('', $agentEvalSimCase->getPurposeName());
        self::assertSame('', $agentEvalSimCase->getReportChannel());
    }

    public function testRunAndRetentionSettingsAreMergedIntoWhatTheConversationCarries(): void
    {
        $conversation = AIConversation::newInstance();
        $runSettings = RunSettings::newInstance();
        $runSettings->evalRun = true;
        $conversation->runSettings = $runSettings;
        $contextRetentionSettings = ContextRetentionSettings::newInstance();
        $contextRetentionSettings->hintRecentToolCallsToDiscourageRepeats = true;
        $conversation->contextRetentionSettings = $contextRetentionSettings;
        $agentEvalSimCase = AgentEvalSimCase::fromArray(static::buildCaseFields());

        $agentEvalSimCase->applyRunSettingsTo($conversation);
        $agentEvalSimCase->applyContextRetentionSettingsTo($conversation);

        self::assertSame($runSettings, $conversation->runSettings);
        self::assertTrue($conversation->runSettings->evalRun);
        self::assertSame(12, $conversation->runSettings->maxStepsPerTurn);
        self::assertSame(0.5, $conversation->runSettings->maxTurnCostUsd);
        self::assertSame($contextRetentionSettings, $conversation->contextRetentionSettings);
        self::assertTrue($conversation->contextRetentionSettings->hintRecentToolCallsToDiscourageRepeats);
        self::assertTrue($conversation->contextRetentionSettings->storeRequestSnapshot);
        self::assertSame(2, $conversation->contextRetentionSettings->maxToolCallImagesOnWire);
    }

    public function testSettingsAreCreatedWhenTheConversationHasNone(): void
    {
        $conversation = AIConversation::newInstance();
        $agentEvalSimCase = AgentEvalSimCase::fromArray(static::buildCaseFields());

        $agentEvalSimCase->applyRunSettingsTo($conversation);
        $agentEvalSimCase->applyContextRetentionSettingsTo($conversation);

        self::assertInstanceOf(RunSettings::class, $conversation->runSettings);
        self::assertSame(12, $conversation->runSettings->maxStepsPerTurn);
        self::assertInstanceOf(ContextRetentionSettings::class, $conversation->contextRetentionSettings);
        self::assertNull($conversation->contextRetentionSettings->hintRecentToolCallsToDiscourageRepeats);
    }

    public function testCompactionSettingsAreBuiltFreshFromTheCase(): void
    {
        $conversation = AIConversation::newInstance();
        $earlierCompactionSettings = CompactionSettings::newInstance();
        $earlierCompactionSettings->keepRecentMessages = 40;
        $conversation->compactionSettings = $earlierCompactionSettings;

        AgentEvalSimCase::fromArray(static::buildCaseFields())->applyCompactionSettingsTo($conversation);

        $compactionSettings = $conversation->compactionSettings;
        self::assertNotSame($earlierCompactionSettings, $compactionSettings);
        self::assertTrue($compactionSettings->enabled);
        self::assertSame(800, $compactionSettings->triggerTokens);
        self::assertSame(4, $compactionSettings->segmentMessages);
        self::assertSame(2, $compactionSettings->tierFanIn);
        self::assertSame('summary-model', $compactionSettings->aiModelName);
        self::assertNull($compactionSettings->keepRecentMessages);
        self::assertNull($compactionSettings->keepRecentTurns);
        self::assertNull($compactionSettings->levelRetention);
    }

    public function testACaseWithoutSettingsBlocksLeavesTheConversationAlone(): void
    {
        $conversation = AIConversation::newInstance();
        $agentEvalSimCase = AgentEvalSimCase::fromArray(['runSettings' => 'not an object', 'compactionSettings' => null]);

        $agentEvalSimCase->applyRunSettingsTo($conversation);
        $agentEvalSimCase->applyContextRetentionSettingsTo($conversation);
        $agentEvalSimCase->applyCompactionSettingsTo($conversation);

        self::assertFalse(isset($conversation->runSettings));
        self::assertFalse(isset($conversation->contextRetentionSettings));
        self::assertFalse(isset($conversation->compactionSettings));
    }
}
