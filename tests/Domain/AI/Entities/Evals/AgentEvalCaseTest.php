<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\RunSettings;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalExpectation;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * One fixture case: the generic keys read through typed accessors, every other key kept as it was, and the tool block
 * of the case — its own `blockedTools` first, then every external-write tool it does not opt into with
 * `allowExternalWrites`; its own block wins over the opt-in.
 */
class AgentEvalCaseTest extends UnitTestCase
{
    /** @var string[] The external-write tools of the tests (an application passes its own list) */
    protected const array EXTERNAL_WRITE_TOOL_NAMES = ['replyToComment', 'updateProfileAttributes', 'updateProfileServices', 'publishProfile', 'publishEntry', 'scheduleEntry', 'deleteEntry'];

    /**
     * A restore case that opts into one external write (the shape of a real fixture case, with neutral values).
     *
     * @return array<string, mixed>
     */
    protected static function buildRestoreCaseFields(): array
    {
        return [
            'id' => 'write.attribute.enable-entrance',
            'allowExternalWrites' => ['updateProfileAttributes'],
            'prompt' => "Switch on the attribute 'step-free entrance' on my public profile.",
            'externalProfileKey' => 4100,
            'goal' => "The boolean attribute 'step-free entrance' becomes yes.",
            'userFacts' => "The instruction is the approval; to 'really?' the owner answers 'yes, do it'.",
            'successRubric' => 'The right identifier, read when needed, then updateProfileAttributes with true and a confirmation.',
            'restore' => [
                'setup' => [
                    'tool' => 'updateProfileAttributes',
                    'args' => ['attributes' => ['elements' => [['identifier' => 'attributes/step_free_entrance', 'values' => ['false']]]]],
                ],
                'writeTool' => 'updateProfileAttributes',
                'writeArgs' => ['attributes' => ['elements' => [['identifier' => 'attributes/step_free_entrance', 'values' => ['false']]]]],
            ],
            'expect' => [
                'skill' => 'Profile.Attributes',
                'tool' => 'updateProfileAttributes',
                'status' => 'COMPLETED',
                'argsContain' => ['attributes' => ['step_free_entrance']],
            ],
        ];
    }

    /**
     * A two-turn case with its own tool block (the shape of a real fixture case, with neutral values).
     *
     * @return array<string, mixed>
     */
    protected static function buildFollowUpCaseFields(): array
    {
        return [
            'id' => 'preload.subskill.loaded-skill.entry-series',
            'prompt' => 'What did I publish last?',
            'followUpPrompts' => ['Plan three entries about seasonal care, spread over next week — only the schedule, no texts yet.'],
            'externalProfileKey' => 4100,
            'goal' => 'First see the latest entries, then get a schedule for a short series.',
            'userFacts' => 'Turn 2 wants the schedule only: nothing saved, nothing published.',
            'successRubric' => 'PASS when turn 1 names the entries and turn 2 gives three dated slots.',
            'blockedTools' => ['publishEntry', 'scheduleEntry', 'createEntry', 'updateEntry', 'deleteEntry'],
            'maxTurns' => 2,
            'expect' => [
                'preloadSkills' => ['Entries.Read', 'none'],
                'tool' => 'proposeEntrySeriesSchedule',
                'status' => 'COMPLETED',
                'forbiddenTools' => ['publishEntry', 'scheduleEntry'],
            ],
        ];
    }

    public function testTheGenericKeysOfARestoreCaseAreReadAndEveryOtherKeyIsKept(): void
    {
        $caseFields = self::buildRestoreCaseFields();
        $agentEvalCase = AgentEvalCase::fromArray($caseFields);

        self::assertSame('write.attribute.enable-entrance', $agentEvalCase->getId());
        self::assertSame($caseFields['prompt'], $agentEvalCase->getPrompt());
        self::assertSame([], $agentEvalCase->getFollowUpPrompts());
        self::assertSame($caseFields['restore'], $agentEvalCase->getRestore());
        self::assertSame($caseFields['expect'], $agentEvalCase->getExpect());
        self::assertNull($agentEvalCase->getStateAfterRun());
        self::assertTrue($agentEvalCase->hasHarnessToolCalls(), 'a restore block makes harness calls');
        self::assertSame(['Profile.Attributes'], $agentEvalCase->getExpectedSkillNames());
        self::assertSame(['updateProfileAttributes'], $agentEvalCase->getExpectedAnyOfToolNames());
        self::assertSame([], $agentEvalCase->getExpectedRequiredToolNames());
        self::assertSame(['updateProfileAttributes'], $agentEvalCase->getAllowedExternalWriteToolNames());
        self::assertSame(4100, $agentEvalCase->getApplicationValue('externalProfileKey'));
        self::assertSame($caseFields['successRubric'], $agentEvalCase->getApplicationValue('successRubric'));
        self::assertTrue($agentEvalCase->hasKey('goal'));
        self::assertNull($agentEvalCase->getApplicationValue('absent'));
        self::assertSame($caseFields, $agentEvalCase->toArray(), 'the case comes back exactly as it was built');
    }

    public function testTheGenericKeysOfAFollowUpCaseAreReadWithTheirDefaults(): void
    {
        $caseFields = self::buildFollowUpCaseFields();
        $agentEvalCase = AgentEvalCase::fromArray($caseFields);

        self::assertSame($caseFields['followUpPrompts'], $agentEvalCase->getFollowUpPrompts());
        self::assertSame([], $agentEvalCase->getFollowUpIdleMinutes());
        self::assertSame(2, $agentEvalCase->getMaxTurns());
        self::assertNull($agentEvalCase->getMaxStepsPerTurn());
        self::assertNull($agentEvalCase->getTimeoutSeconds());
        self::assertNull($agentEvalCase->getPurposeName());
        self::assertNull($agentEvalCase->getReportChannel());
        self::assertFalse($agentEvalCase->isScheduledRun());
        self::assertNull($agentEvalCase->getAgentSpaceId());
        self::assertSame($caseFields['blockedTools'], $agentEvalCase->getBlockedToolNames());
        self::assertSame([], $agentEvalCase->getBlockedToolSimulatedErrors());
        self::assertFalse($agentEvalCase->isSequential());
        self::assertSame(AgentEvalCase::EXECUTION_MODE_SYNC, $agentEvalCase->getExecutionMode());
        self::assertFalse($agentEvalCase->isAsyncOnly());
        self::assertFalse($agentEvalCase->requiresVision());
        self::assertFalse($agentEvalCase->hasTurns());
        self::assertSame([], $agentEvalCase->getTurns());
        self::assertSame([], $agentEvalCase->getRequirementNames());
        self::assertNull($agentEvalCase->getRestore());
        self::assertFalse($agentEvalCase->hasHarnessToolCalls(), 'a transient case without restore and state check makes no harness call');
        self::assertSame($caseFields, $agentEvalCase->toArray());
    }

    public function testTheReportChannelAndTheScheduledRunAreReadAsTheToolsSuiteReadThem(): void
    {
        $emptyChannelCase = AgentEvalCase::fromArray(['reportChannel' => '', 'scheduledRun' => 1]);
        self::assertSame('', $emptyChannelCase->getReportChannel(), 'a set key is read as text, an empty text included');
        self::assertTrue($emptyChannelCase->isScheduledRun(), 'a truthy value counts as a schedule firing');

        $numericChannelCase = AgentEvalCase::fromArray(['reportChannel' => 7, 'scheduledRun' => 'yes']);
        self::assertSame('7', $numericChannelCase->getReportChannel());
        self::assertTrue($numericChannelCase->isScheduledRun());

        $unsetCase = AgentEvalCase::fromArray(['reportChannel' => null, 'scheduledRun' => 0]);
        self::assertNull($unsetCase->getReportChannel());
        self::assertFalse($unsetCase->isScheduledRun());
    }

    public function testTheRemainingGenericKeysAreReadAsWritten(): void
    {
        $agentEvalCase = AgentEvalCase::fromArray([
            'id' => 'memory.flow',
            'turns' => ['Remember: two dogs.', 'Correct that — three dogs.'],
            'followUpIdleMinutes' => [0, '45'],
            'maxStepsPerTurn' => '12',
            'timeoutSeconds' => 900,
            'purposeName' => 'Schedule.Run',
            'reportChannel' => 'EMAIL',
            'scheduledRun' => true,
            'agentSpaceId' => '17',
            'blockedToolSimulatedErrors' => ['publishEntry' => 'the channel is down'],
            'sequential' => true,
            'executionMode' => ' ASYNC ',
            'requiresVision' => true,
            'requires' => ['dailyLimit', 7],
            'runsOnIneligibleScope' => true,
            'runsWithoutCapability' => 'yes',
            'expect' => [
                'skills' => ['Notes.Read', 'Notes.Write'],
                'skill' => 'Notes.Read',
                'anyOfSkills' => ['Calendar.Events'],
                'requiredTools' => 'writeNote',
                'stateAfterRun' => ['readTool' => 'readNote', 'fields' => ['note.text' => ['maxLength' => 10]]],
            ],
        ]);

        self::assertTrue($agentEvalCase->hasTurns());
        self::assertSame(['Remember: two dogs.', 'Correct that — three dogs.'], $agentEvalCase->getTurns());
        self::assertSame([0, 45], $agentEvalCase->getFollowUpIdleMinutes());
        self::assertSame(12, $agentEvalCase->getMaxStepsPerTurn());
        self::assertSame(900, $agentEvalCase->getTimeoutSeconds());
        self::assertSame('Schedule.Run', $agentEvalCase->getPurposeName());
        self::assertSame('EMAIL', $agentEvalCase->getReportChannel());
        self::assertTrue($agentEvalCase->isScheduledRun());
        self::assertSame(17, $agentEvalCase->getAgentSpaceId());
        self::assertSame(['publishEntry' => 'the channel is down'], $agentEvalCase->getBlockedToolSimulatedErrors());
        self::assertTrue($agentEvalCase->isSequential());
        self::assertSame(AgentEvalCase::EXECUTION_MODE_ASYNC, $agentEvalCase->getExecutionMode());
        self::assertTrue($agentEvalCase->isAsyncOnly());
        self::assertTrue($agentEvalCase->requiresVision());
        self::assertSame(['dailyLimit', '7'], $agentEvalCase->getRequirementNames());
        self::assertTrue($agentEvalCase->runsOnIneligibleScope());
        self::assertFalse($agentEvalCase->runsWithoutCapability(), 'only a literal true opts in');
        self::assertSame(['Notes.Read', 'Notes.Write'], $agentEvalCase->getExpectedSkillNames());
        self::assertSame(['Calendar.Events'], $agentEvalCase->getExpectedAnyOfSkillNames());
        self::assertSame(['writeNote'], $agentEvalCase->getExpectedRequiredToolNames());
        self::assertSame(['readTool' => 'readNote', 'fields' => ['note.text' => ['maxLength' => 10]]], $agentEvalCase->getStateAfterRun());
        self::assertTrue($agentEvalCase->hasHarnessToolCalls(), 'a state check makes harness calls');
    }

    public function testTheCaseAndItsExpectationReadTheExpectedSkillsAlike(): void
    {
        $expectBlocks = [
            ['skills' => ['Notes.Read', 'Notes.Write'], 'skill' => 'Notes.Read'],
            ['skill' => 'Calendar.Events'],
            ['skill' => ['not', 'a', 'name'], 'skills' => ['Notes.Read']],
            ['skill' => 7],
            [],
        ];
        foreach ($expectBlocks as $expectBlock) {
            self::assertSame(
                AgentEvalExpectation::fromExpectBlock($expectBlock)->getExpectedSkillNames(),
                AgentEvalCase::fromArray(['expect' => $expectBlock])->getExpectedSkillNames(),
                (string)json_encode($expectBlock)
            );
        }
        self::assertSame(['Notes.Read'], AgentEvalCase::fromArray(['expect' => ['skill' => ['not', 'a', 'name'], 'skills' => ['Notes.Read']]])->getExpectedSkillNames());
        self::assertSame([], AgentEvalCase::fromArray(['expect' => ['skill' => 7]])->getExpectedSkillNames(), 'a `skill` that is no string names no skill');
    }

    public function testTheRequirementsOfACaseAreTheNamesInItsRequiresKeyOnly(): void
    {
        self::assertSame([], AgentEvalCase::fromArray(['expect' => ['tool' => 'readNotes']])->getRequirementNames());
        self::assertSame(
            ['dailyLimit'],
            AgentEvalCase::fromArray(['requires' => ['dailyLimit'], 'expect' => ['tool' => ['scheduleEvent', 'readNotes']]])->getRequirementNames()
        );
        self::assertSame([], AgentEvalCase::fromArray(['requires' => 'dailyLimit'])->getRequirementNames(), 'a single name is no list');
    }

    public function testACaseWithoutAnOptInGetsEveryExternalWriteBlockedAfterItsOwnBlock(): void
    {
        $blockedToolNames = AgentEvalCase::fromArray([
            'id' => 'plan.direct.single-action.reply-draft',
            'blockedTools' => ['updateOpeningHours'],
        ])->resolveBlockedToolNames(self::EXTERNAL_WRITE_TOOL_NAMES);

        self::assertSame('updateOpeningHours', $blockedToolNames[0], 'the case\'s own block comes first');
        foreach (self::EXTERNAL_WRITE_TOOL_NAMES as $externalWriteToolName) {
            self::assertContains($externalWriteToolName, $blockedToolNames);
        }
        self::assertNotContains('grantMandate', $blockedToolNames, 'a tool outside the list is not blocked');
        self::assertNotContains('draftCommentReply', $blockedToolNames, 'a draft is no external write');
    }

    public function testAnOptInLiftsOnlyTheNamedToolsAndTheCaseBlockWins(): void
    {
        $agentEvalCase = AgentEvalCase::fromArray([
            'allowExternalWrites' => ['updateProfileAttributes', 'publishEntry'],
            'blockedTools' => ['publishEntry'],
        ]);
        $blockedToolNames = $agentEvalCase->resolveBlockedToolNames(self::EXTERNAL_WRITE_TOOL_NAMES);

        self::assertNotContains('updateProfileAttributes', $blockedToolNames);
        self::assertContains('updateProfileServices', $blockedToolNames);
        self::assertContains('publishEntry', $blockedToolNames, 'the case\'s own blockedTools win over the opt-in');
        self::assertSame(array_values(array_unique($blockedToolNames)), $blockedToolNames);
        self::assertSame(
            ['replyToComent'],
            AgentEvalCase::fromArray(['allowExternalWrites' => ['replyToComent', 'replyToComment']])->findUnknownAllowedExternalWriteToolNames(self::EXTERNAL_WRITE_TOOL_NAMES)
        );
        self::assertSame([], AgentEvalCase::fromArray([])->getAllowedExternalWriteToolNames());
    }

    public function testTheBlockRidesOnTheConversationSoAWorkerRefusesTheWriteToo(): void
    {
        $blockedToolNames = AgentEvalCase::fromArray([])->resolveBlockedToolNames(self::EXTERNAL_WRITE_TOOL_NAMES);
        $runSettings = new RunSettings();
        $runSettings->evalRun = true;
        $runSettings->setEvalToolBlock($blockedToolNames, [], false);

        self::assertNotNull($runSettings->describeEvalToolBlock('replyToComment'));
        self::assertNotNull($runSettings->describeEvalToolBlock('updateProfileAttributes'));
        self::assertNotNull($runSettings->describeEvalToolBlock('publishProfile'));
        self::assertNull($runSettings->describeEvalToolBlock('listComments'));
    }
}
