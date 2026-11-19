<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\ModelInputOptions;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentBasePromptSections;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInputOptions;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInputOptionsOverride;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\Agent\OwnerPresence;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\AskOwnerTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\CheckDelegationsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\DelegateTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\FindWidgetsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\GetTaskPlansTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\MessageSubAgentTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\SetTaskStatusTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\UpdateTaskPlanTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\WaitForSubAgentsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedTools;
use DDD\Domain\AI\Entities\Conversations\Agent\Turns\AgentOfferedToolsInputs;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyToolSet;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The token switches of the model input: every default leaves the model input byte-identical; a section is dropped
 * whole and every other line of the prompt stays verbatim; the meta tool groups, the widget part and the first-call
 * tool set go away only when switched off.
 */
class AgentModelInputOptionsTest extends UnitTestCase
{
    protected const string PACKAGE_BASE_PROMPT_FILE = '/config/app/AI/Prompts/AI/Agent/Agentic.md';

    public function testTheDefaultsLeaveTheBasePromptByteIdentical(): void
    {
        $packageBasePrompt = $this->readPackageBasePrompt();
        $defaultOptions = AgentModelInputOptions::resolveFor();

        self::assertSame([], $defaultOptions->buildDroppedBasePromptSections(false));
        self::assertSame([], $defaultOptions->buildDroppedBasePromptSections(true));
        self::assertTrue($defaultOptions->teachesWidgets);
        self::assertSame($packageBasePrompt, AgentBasePromptSections::dropSections($packageBasePrompt, []));
        self::assertSame($packageBasePrompt, AgentBasePromptSections::dropSections($packageBasePrompt, ['A heading the prompt does not have']));

        $contextWithoutOptions = $this->buildModelInput($packageBasePrompt);
        $contextWithDefaultOptions = $this->buildModelInput($packageBasePrompt);
        $contextWithDefaultOptions->teachesWidgets = $defaultOptions->teachesWidgets;
        $contextWithDefaultOptions->droppedBasePromptSections = $defaultOptions->buildDroppedBasePromptSections(false);
        self::assertSame($contextWithoutOptions->buildBasePromptForThisCall(), $contextWithDefaultOptions->buildBasePromptForThisCall());
    }

    public function testADroppedSectionGoesWholeAndEveryOtherLineStaysVerbatim(): void
    {
        $packageBasePrompt = $this->readPackageBasePrompt();
        $headings = AgentBasePromptSections::findSectionHeadings($packageBasePrompt);
        self::assertContains(AgentModelInputOptions::BASE_PROMPT_SECTION_WIDGETS, $headings, 'the widget section the switch names');
        self::assertContains(AgentModelInputOptions::BASE_PROMPT_SECTION_MEMORY, $headings, 'the memory section the switch names');

        foreach ([AgentModelInputOptions::BASE_PROMPT_SECTION_WIDGETS, AgentModelInputOptions::BASE_PROMPT_SECTION_MEMORY, 'Status line + parallel calls'] as $droppedHeading) {
            $promptWithoutSection = AgentBasePromptSections::dropSections($packageBasePrompt, [$droppedHeading]);
            self::assertNotContains($droppedHeading, AgentBasePromptSections::findSectionHeadings($promptWithoutSection));
            self::assertSame(count($headings) - 1, count(AgentBasePromptSections::findSectionHeadings($promptWithoutSection)));
            $this->assertLinesAreAnOrderedSubsetOf(explode("\n", $promptWithoutSection), explode("\n", $packageBasePrompt));
        }
        $promptWithoutWidgets = AgentBasePromptSections::dropSections($packageBasePrompt, [AgentModelInputOptions::BASE_PROMPT_SECTION_WIDGETS]);
        self::assertStringNotContainsString('{%COMPOSED_VIEW_KIT%}', $promptWithoutWidgets, 'the kit sits in the widget section');
        self::assertStringContainsString('## [surface=phone] No widgets on a call', $promptWithoutWidgets);
        self::assertSame($promptWithoutWidgets, AgentBasePromptSections::dropSections($packageBasePrompt, ['[surface=screen] Widgets']), 'with or without the marker');
    }

    public function testAHeadingInsideACodeFenceIsNoSection(): void
    {
        $promptText = "# Head\n\n## Kept\nText\n```\n## Not a heading\n```\nmore\n\n## Dropped\nGone\n\n## Last\nEnd";

        self::assertSame("# Head\n\n## Kept\nText\n```\n## Not a heading\n```\nmore\n\n## Last\nEnd", AgentBasePromptSections::dropSections($promptText, ['Dropped']));
        self::assertSame($promptText, AgentBasePromptSections::dropSections($promptText, ['Not a heading']));
    }

    public function testWithoutWidgetsTheKitAndTheWidgetSectionAreLeftOut(): void
    {
        $agentModelInput = $this->buildModelInput("# Head\n\n## Answering\nAnswer.\n\n## [surface=screen] Widgets\nWrite widgets.\n\n{%COMPOSED_VIEW_KIT%}\n\n## Tail\nEnd.");
        $agentModelInput->ownerPresence = OwnerPresence::PRESENT_IN_CHAT;
        $agentModelInputOptions = (new AgentModelInputOptions())->applyOptionsMap(['teachesWidgets' => false]);
        $agentModelInput->teachesWidgets = $agentModelInputOptions->teachesWidgets;
        $agentModelInput->droppedBasePromptSections = $agentModelInputOptions->buildDroppedBasePromptSections(false);

        self::assertFalse($agentModelInput->teachesComposedViewKit());
        self::assertSame("# Head\n\n## Answering\nAnswer.\n\n## Tail\nEnd.", $agentModelInput->buildBasePromptForThisCall());
        self::assertFalse($agentModelInputOptions->offersFindWidgetsWithWidgetsTaught(), 'findWidgets goes with the widgets');
    }

    public function testTheMemorySectionIsLeftOutOnlyWhenAskedAndNoMemoryToolIsReachable(): void
    {
        $agentModelInputOptions = (new AgentModelInputOptions())->applyOptionsMap(['dropsMemorySectionWithoutMemoryTool' => true, 'droppedBasePromptSections' => ['Speed — the owner is waiting']]);

        self::assertSame(['Speed — the owner is waiting', AgentModelInputOptions::BASE_PROMPT_SECTION_MEMORY], $agentModelInputOptions->buildDroppedBasePromptSections(false));
        self::assertSame(['Speed — the owner is waiting'], $agentModelInputOptions->buildDroppedBasePromptSections(true));
    }

    public function testAPurposeOverridesOnlyTheKeysItNames(): void
    {
        $agentModelInputOptionsOverride = AgentModelInputOptionsOverride::fromOptionsMap(['offersAskOwner' => false, 'droppedBasePromptSections' => ['Grounding'], 'unknownKey' => true]);
        $agentModelInputOptions = (new AgentModelInputOptions())->applyOptionsMap(['offersTaskPlanTools' => false]);
        $agentModelInputOptionsOverride->applyTo($agentModelInputOptions);

        self::assertFalse($agentModelInputOptions->offersAskOwner);
        self::assertFalse($agentModelInputOptions->offersTaskPlanTools, 'the host\'s value where the purpose names none');
        self::assertTrue($agentModelInputOptions->offersDelegationTools);
        self::assertSame(['Grounding'], $agentModelInputOptions->droppedBasePromptSections);
        self::assertNull($agentModelInputOptionsOverride->teachesWidgets);
    }

    public function testTheMetaToolGroupsAreOfferedByDefaultAndLeaveWhenSwitchedOff(): void
    {
        $defaultNames = AgentOfferedTools::buildFor($this->emptySkillCatalog(), $this->buildOfferInputs())->getToolNames();
        self::assertSame(
            [DelegateTool::NAME, CheckDelegationsTool::NAME, MessageSubAgentTool::NAME, WaitForSubAgentsTool::NAME, UpdateTaskPlanTool::NAME, SetTaskStatusTool::NAME, AskOwnerTool::NAME, FindWidgetsTool::NAME, GetTaskPlansTool::NAME],
            $defaultNames
        );

        $withoutDelegation = $this->buildOfferInputs();
        $withoutDelegation->offersDelegationTools = false;
        self::assertSame([UpdateTaskPlanTool::NAME, SetTaskStatusTool::NAME, AskOwnerTool::NAME, FindWidgetsTool::NAME, GetTaskPlansTool::NAME], AgentOfferedTools::buildFor($this->emptySkillCatalog(), $withoutDelegation)->getToolNames());

        $withoutPlansAskOwnerAndWidgets = $this->buildOfferInputs();
        $withoutPlansAskOwnerAndWidgets->offersTaskPlanTools = false;
        $withoutPlansAskOwnerAndWidgets->offersAskOwner = false;
        $withoutPlansAskOwnerAndWidgets->offersFindWidgets = false;
        self::assertSame([DelegateTool::NAME, CheckDelegationsTool::NAME, MessageSubAgentTool::NAME, WaitForSubAgentsTool::NAME], AgentOfferedTools::buildFor($this->emptySkillCatalog(), $withoutPlansAskOwnerAndWidgets)->getToolNames());
    }

    public function testLoadingBySkillKeepsTheReachableSetOffTheFirstCallWhateverItsSize(): void
    {
        $smallReachableSet = ToolDefinitions::forPackages([]);
        self::assertFalse(AgentAppendOnlyToolSet::decideFor($smallReachableSet, [], AgentAppendOnlyToolSet::CEILING_TOKENS_OF_LOADING_BY_SKILL)->offersReachableToolsTopLevel());
        self::assertTrue((new AgentModelInputOptions())->offersReachableToolsFromTheFirstCall, 'the default sends it from the first call');
        self::assertFalse((new AgentModelInputOptions())->applyOptionsMap(['offersReachableToolsFromTheFirstCall' => false])->offersReachableToolsFromTheFirstCall);
    }

    /**
     * @param string[] $resultLines
     * @param string[] $originalLines
     */
    protected function assertLinesAreAnOrderedSubsetOf(array $resultLines, array $originalLines): void
    {
        $originalPosition = 0;
        foreach ($resultLines as $resultLine) {
            while ($originalPosition < count($originalLines) && $originalLines[$originalPosition] !== $resultLine) {
                $originalPosition++;
            }
            self::assertLessThan(count($originalLines), $originalPosition, 'a line that is not verbatim in the prompt: ' . $resultLine);
            $originalPosition++;
        }
    }

    protected function buildModelInput(string $systemPrompt): AgentModelInput
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->systemPrompt = $systemPrompt;
        return $agentModelInput;
    }

    protected function readPackageBasePrompt(): string
    {
        return (string)file_get_contents(dirname(__DIR__, 7) . self::PACKAGE_BASE_PROMPT_FILE);
    }

    protected function buildOfferInputs(): AgentOfferedToolsInputs
    {
        $agentOfferedToolsInputs = new AgentOfferedToolsInputs();
        $agentOfferedToolsInputs->allowThreads = true;
        $agentOfferedToolsInputs->hasDelegateCall = true;
        $agentOfferedToolsInputs->offersWaitForSubAgents = true;
        $agentOfferedToolsInputs->offersFindWidgets = true;
        $agentOfferedToolsInputs->offersConditionalMetaToolsFromTheStart = true;
        $agentOfferedToolsInputs->loadedSkillToolDefinitions = new ToolDefinitions();
        return $agentOfferedToolsInputs;
    }

    protected function emptySkillCatalog(): OfferedSkillCatalog
    {
        /** @var OfferedSkillCatalog $offeredSkillCatalog */
        $offeredSkillCatalog = self::instantiateWithoutConstructor(OfferedSkillCatalog::class);
        $offeredSkillCatalog->skills = new AISkills();
        $offeredSkillCatalog->alreadyLoadedSkillNames = [];
        return $offeredSkillCatalog;
    }
}
