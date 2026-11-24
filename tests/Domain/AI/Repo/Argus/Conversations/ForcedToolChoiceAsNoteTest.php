<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentModelInputTailEntry;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Conversations\ArgusAIAgentConversation;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A forced tool choice goes out as `auto` plus the `[SIGNAL:tool-expected]` tail line when the catalog says the model
 * rejects a forced choice (Claude Opus 5.5, Fable 5.1); every other model keeps the forced choice.
 */
class ForcedToolChoiceAsNoteTest extends UnitTestCase
{
    protected const array NAMED_PIN = ['type' => 'function', 'function' => ['name' => 'deliverReport']];

    public function testAModelThatRejectsAForcedChoiceGetsAutoAndANote(): void
    {
        $argusAIAgentConversation = $this->buildEgress(false, self::NAMED_PIN);

        self::assertTrue($argusAIAgentConversation->sendsForcedToolChoiceAsNote());
        self::assertSame('auto', self::invokeMethod($argusAIAgentConversation, 'getChatCompletionToolChoice'));
        $toolExpectedTexts = $this->collectToolExpectedTexts(self::NAMED_PIN, true);
        self::assertCount(1, $toolExpectedTexts);
        self::assertStringContainsString('`deliverReport`', $toolExpectedTexts[0]);
    }

    public function testAModelThatAcceptsAForcedChoiceKeepsItAndGetsNoNote(): void
    {
        $argusAIAgentConversation = $this->buildEgress(true, self::NAMED_PIN);

        self::assertFalse($argusAIAgentConversation->sendsForcedToolChoiceAsNote());
        self::assertSame(self::NAMED_PIN, self::invokeMethod($argusAIAgentConversation, 'getChatCompletionToolChoice'));
        self::assertSame([], $this->collectToolExpectedTexts(self::NAMED_PIN, false));
    }

    public function testRequiredBecomesAutoWithAGeneralNoteAndNoneIsNeverReplaced(): void
    {
        $requiredEgress = $this->buildEgress(false, 'required');
        self::assertSame('auto', self::invokeMethod($requiredEgress, 'getChatCompletionToolChoice'));
        self::assertStringContainsString('one of your tools', $this->collectToolExpectedTexts('required', true)[0]);

        $noneEgress = $this->buildEgress(false, 'none');
        self::assertFalse($noneEgress->sendsForcedToolChoiceAsNote());
        self::assertSame('none', self::invokeMethod($noneEgress, 'getChatCompletionToolChoice'));
        self::assertSame([], $this->collectToolExpectedTexts('none', true), 'none is no forced tool call');
    }

    public function testAnUndecidedModelForcesAsBefore(): void
    {
        $argusAIAgentConversation = $this->buildEgress(null, 'required');

        self::assertFalse($argusAIAgentConversation->sendsForcedToolChoiceAsNote());
        self::assertSame('required', self::invokeMethod($argusAIAgentConversation, 'getChatCompletionToolChoice'));
    }

    /**
     * @param string|array<string, mixed> $forceToolChoice
     */
    protected function buildEgress(?bool $supportsForcedToolChoice, string|array $forceToolChoice): ArgusAIAgentConversation
    {
        /** @var ArgusAIAgentConversation $argusAIAgentConversation */
        $argusAIAgentConversation = self::instantiateWithoutConstructor(ArgusAIAgentConversation::class);
        $aiModel = new AIModel();
        $aiModel->name = 'PROBE.MODEL';
        $aiModel->supportsForcedToolChoice = $supportsForcedToolChoice;
        self::setPropertyValue($argusAIAgentConversation, 'aiModel', $aiModel);
        $argusAIAgentConversation->toolSchemas = [['type' => 'function', 'function' => ['name' => 'deliverReport', 'parameters' => ['type' => 'object']]]];
        $argusAIAgentConversation->forceToolChoice = $forceToolChoice;
        return $argusAIAgentConversation;
    }

    /**
     * @param string|array<string, mixed> $forceToolChoice
     * @return string[]
     */
    protected function collectToolExpectedTexts(string|array $forceToolChoice, bool $forcedToolChoiceSentAsNote): array
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->messages = [];
        $agentModelInput->timezone = new \DateTimeZone('UTC');
        $agentModelInput->clockInstant = DateTime::fromTimestamp(1_789_000_000);
        $toolExpectedTexts = [];
        foreach ($agentModelInput->buildTailEntries($forceToolChoice, false, $forcedToolChoiceSentAsNote)->getElements() as $agentModelInputTailEntry) {
            if ($agentModelInputTailEntry->kind === AgentModelInputTailEntry::KIND_TOOL_EXPECTED) {
                $toolExpectedTexts[] = $agentModelInputTailEntry->text;
            }
        }
        return $toolExpectedTexts;
    }
}
