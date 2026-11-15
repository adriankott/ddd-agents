<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Evals\AgentEvalApplicabilityFacts;
use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason;
use DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReasons;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException;
use DDD\Tests\Agents\Support\UnitTestCase;
use RuntimeException;

/**
 * A case its agent space cannot serve is not applicable: neither a pass nor a fail, and outside every pass rate.
 */
class AgentEvalApplicabilityFactsTest extends UnitTestCase
{
    protected const array CATALOG_SKILLS = ['Notes.Read', 'Notes.Write', 'Calendar.Events', 'Calendar.Publishing'];
    protected const array CATALOG_TOOLS = ['readNotes', 'writeNote', 'listEvents', 'scheduleEvent', 'publishEvent'];

    /**
     * @param array<string, mixed> $caseFields
     * @param array<string, string> $withheldSkillReasons
     * @param string[] $availableToolNames
     * @param AgentEvalNotApplicableReasons|null $unmetRequirementReasons
     */
    protected function decide(
        array $caseFields,
        ?string $ineligibleScopeReason = null,
        array $withheldSkillReasons = [],
        array $availableToolNames = self::CATALOG_TOOLS,
        ?AgentEvalNotApplicableReasons $unmetRequirementReasons = null
    ): ?AgentEvalNotApplicableReason {
        $agentEvalApplicabilityFacts = AgentEvalApplicabilityFacts::forCase(AgentEvalCase::fromArray($caseFields));
        $agentEvalApplicabilityFacts->ineligibleScopeReason = $ineligibleScopeReason;
        $agentEvalApplicabilityFacts->catalogSkillNames = self::CATALOG_SKILLS;
        $agentEvalApplicabilityFacts->withheldSkillReasons = $withheldSkillReasons;
        $agentEvalApplicabilityFacts->catalogToolNames = self::CATALOG_TOOLS;
        $agentEvalApplicabilityFacts->availableToolNames = $availableToolNames;
        if ($unmetRequirementReasons !== null) {
            $agentEvalApplicabilityFacts->unmetRequirementReasons = $unmetRequirementReasons;
        }
        return $agentEvalApplicabilityFacts->findNotApplicableReason();
    }

    /**
     * The unmet requirements in the given order, each as the application's hooks give it.
     *
     * @param array<string, array{0: string, 1: string}> $reasonAndDetailByRequirementName
     */
    protected function unmetRequirementReasonsOf(array $reasonAndDetailByRequirementName): AgentEvalNotApplicableReasons
    {
        $unmetRequirementReasons = AgentEvalNotApplicableReasons::newInstance();
        foreach ($reasonAndDetailByRequirementName as $requirementName => [$reason, $detail]) {
            $unmetRequirementReason = AgentEvalNotApplicableReason::forUnmetRequirement((string)$requirementName, $reason, $detail);
            $unmetRequirementReasons->add($unmetRequirementReason);
        }
        return $unmetRequirementReasons;
    }

    public function testAnUnmetRequirementOfTheAgentSpaceMakesTheCaseNotApplicableWithTheReasonTheApplicationGave(): void
    {
        $unmetRequirementReasons = $this->unmetRequirementReasonsOf(['dailyLimit' => ['limitReached', 'the space has used up its 5 new entries of the day']]);

        $notApplicable = $this->decide(['requires' => ['dailyLimit'], 'expect' => ['tool' => 'readNotes']], null, [], self::CATALOG_TOOLS, $unmetRequirementReasons);
        self::assertSame('limitReached', $notApplicable?->getReason());
        self::assertStringContainsString('used up', $notApplicable->getDetail());

        self::assertNull($this->decide(['expect' => ['tool' => 'readNotes']]), 'a case nothing is unmet for applies');
    }

    public function testTheFirstUnmetRequirementDecides(): void
    {
        $held = $this->decide(['expect' => ['tool' => 'publishEvent']], null, [], self::CATALOG_TOOLS, $this->unmetRequirementReasonsOf([
            'calendarEdit' => ['calendarHeldByOpenEdit', 'the calendar is held by the open edit #2370'],
            'calendarRebuild' => ['limitReached', 'the calendar cannot be rebuilt now: 20 of 10 this month'],
        ]));
        self::assertSame('calendarHeldByOpenEdit', $held?->getReason());
        self::assertStringContainsString('#2370', $held->getDetail());

        $limitReached = $this->decide(['expect' => ['tool' => 'publishEvent']], null, [], self::CATALOG_TOOLS, $this->unmetRequirementReasonsOf([
            'calendarRebuild' => ['limitReached', 'the calendar cannot be rebuilt now: 20 of 10 this month'],
        ]));
        self::assertSame('limitReached', $limitReached?->getReason());
    }

    public function testACaseWhoseAgentSpaceOffersEverythingApplies(): void
    {
        self::assertNull($this->decide(['expect' => ['skill' => 'Notes.Read', 'tool' => 'readNotes']]));
    }

    public function testALockedScopeMakesEveryCaseNotApplicable(): void
    {
        $notApplicable = $this->decide(['expect' => ['skill' => 'Notes.Read']], 'SUSPENDED (SUBSCRIPTION_LOCKED)');
        self::assertSame(AgentEvalNotApplicableReason::REASON_SCOPE_NOT_ELIGIBLE, $notApplicable?->getReason());
        self::assertSame('the scope is not eligible for automation: SUSPENDED (SUBSCRIPTION_LOCKED)', $notApplicable->getDetail());
    }

    public function testACaseThatTestsTheRefusalItselfStaysApplicableOnALockedScope(): void
    {
        self::assertNull($this->decide(['runsOnIneligibleScope' => true, 'expect' => []], 'SUSPENDED (SUBSCRIPTION_LOCKED)'));
    }

    public function testACaseAboutTheAnswerToAWithheldCapabilityRunsWhereItIsWithheld(): void
    {
        $withheld = ['Notes.Write' => 'the plan lacks the feature flag NOTES.WRITE'];
        self::assertNull($this->decide(['runsWithoutCapability' => true, 'expect' => ['skill' => 'Notes.Write', 'tool' => 'writeNote']], null, $withheld, ['readNotes']));
        self::assertNotNull(
            $this->decide(['runsWithoutCapability' => true, 'expect' => []], 'LOCKED'),
            'a locked scope is decided before the opt-in'
        );
    }

    public function testAnExpectedSkillTheAgentSpaceIsNotOfferedMakesTheCaseNotApplicable(): void
    {
        $notApplicable = $this->decide(
            ['expect' => ['skills' => ['Notes.Read'], 'skill' => 'Notes.Write']],
            null,
            ['Notes.Write' => 'the plan lacks the feature flag NOTES.WRITE']
        );
        self::assertSame(AgentEvalNotApplicableReason::REASON_SKILL_NOT_AVAILABLE, $notApplicable?->getReason());
        self::assertSame(
            "the expected skill 'Notes.Write' is not offered to this " . AgentApplicationWording::current()->agentSpaceName . ': the plan lacks the feature flag NOTES.WRITE',
            $notApplicable->getDetail()
        );
    }

    public function testTheSentencesNameTheAgentSpaceWithTheApplicationsWord(): void
    {
        $agentEvalApplicabilityFacts = AgentEvalApplicabilityFacts::forCase(AgentEvalCase::fromArray(['expect' => ['requiredTools' => ['scheduleEvent']]]));
        $agentEvalApplicabilityFacts->catalogToolNames = self::CATALOG_TOOLS;
        $agentEvalApplicabilityFacts->availableToolNames = ['readNotes'];
        $agentEvalApplicabilityFacts->agentSpaceName = 'studio';

        self::assertSame("the required tool 'scheduleEvent' is not offered to this studio", $agentEvalApplicabilityFacts->findNotApplicableReason()?->getDetail());
    }

    public function testASkillTheCatalogDoesNotKnowStaysAFailureOfTheFixture(): void
    {
        self::assertNull($this->decide(['expect' => ['skill' => 'Notes.Typo']], null, ['Notes.Typo' => 'never offered']));
    }

    public function testAnyOfSkillsApplyWhileOneOfThemIsOffered(): void
    {
        $withheld = ['Notes.Write' => 'the plan lacks the feature flag NOTES.WRITE'];
        self::assertNull($this->decide(['expect' => ['anyOfSkills' => ['Notes.Write', 'Notes.Read']]], null, $withheld));
        $notApplicable = $this->decide(['expect' => ['anyOfSkills' => ['Notes.Write']]], null, $withheld);
        self::assertSame(AgentEvalNotApplicableReason::REASON_SKILL_NOT_AVAILABLE, $notApplicable?->getReason());
        self::assertStringContainsString('none of the expected skills [Notes.Write]', $notApplicable->getDetail());
    }

    public function testAnyOfToolsApplyWhileOneOfThemIsOffered(): void
    {
        self::assertNull($this->decide(['expect' => ['tool' => ['scheduleEvent', 'publishEvent']]], null, [], ['publishEvent']));
        $notApplicable = $this->decide(['expect' => ['tool' => ['scheduleEvent', 'publishEvent']]], null, [], ['listEvents']);
        self::assertSame(AgentEvalNotApplicableReason::REASON_TOOL_NOT_AVAILABLE, $notApplicable?->getReason());
    }

    public function testOneWithheldRequiredToolIsEnough(): void
    {
        $notApplicable = $this->decide(['expect' => ['requiredTools' => ['listEvents', 'scheduleEvent']]], null, [], ['listEvents']);
        self::assertSame(AgentEvalNotApplicableReason::REASON_TOOL_NOT_AVAILABLE, $notApplicable?->getReason());
        self::assertNull($this->decide(['expect' => ['requiredTools' => 'listEvents']], null, [], ['listEvents']));
    }

    public function testAToolNoSkillExposesNeverMakesACaseNotApplicable(): void
    {
        // a meta tool (load_skills, delegate) and a misspelled tool are not part of any skill's packages
        self::assertNull($this->decide(['expect' => ['tool' => 'load_skills']], null, [], []));
    }

    public function testACaseWhosePreparationStepFailedGetsItsOwnReason(): void
    {
        $notApplicable = AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason(
            "the preparation step 'replyToComment' of the case failed, the agent was not run: refused"
        );

        self::assertSame(AgentEvalNotApplicableReason::REASON_PRECONDITION_NOT_ESTABLISHED, $notApplicable->getReason());
        self::assertSame(
            ['reason' => 'preconditionNotEstablished', 'detail' => "the preparation step 'replyToComment' of the case failed, the agent was not run: refused"],
            $notApplicable->toArray()
        );
        self::assertInstanceOf(RuntimeException::class, new AgentEvalPreconditionNotEstablishedException('refused'));
    }

    public function testTheNeutralReasonsKeepTheirStoredValues(): void
    {
        self::assertSame('scopeNotEligible', AgentEvalNotApplicableReason::REASON_SCOPE_NOT_ELIGIBLE);
        self::assertSame('skillNotAvailable', AgentEvalNotApplicableReason::REASON_SKILL_NOT_AVAILABLE);
        self::assertSame('toolNotAvailable', AgentEvalNotApplicableReason::REASON_TOOL_NOT_AVAILABLE);
        self::assertSame('preconditionNotEstablished', AgentEvalNotApplicableReason::REASON_PRECONDITION_NOT_ESTABLISHED);
    }
}
