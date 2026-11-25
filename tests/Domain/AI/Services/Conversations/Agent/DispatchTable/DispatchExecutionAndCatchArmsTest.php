<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\DispatchTable;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Exceptions\AiBudgetExceededException;
use DDD\Domain\AI\Exceptions\AIScopeNotEligibleException;
use DDD\Domain\AI\Exceptions\Conversations\AgentRunClaimLostException;
use DDD\Domain\AI\Exceptions\Conversations\AgentToolExecutionTimeoutException;
use DDD\Domain\AI\Exceptions\Conversations\MessageIndexLockUnavailableException;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\ToolPictureFixtures;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

/**
 * The execution branch of {@see \DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallDispatchService::dispatchToolCall()}
 * and the steps after it (the refusal of an owner gate, a result that ends the purpose, pictures, the use of the loaded
 * tool), and the catch arms: the budget, the scope that permits no automated work, any other error with the timeout among
 * them, and the two exits of the loop that are thrown on.
 */
class DispatchExecutionAndCatchArmsTest extends UnitTestCase
{
    use DispatchTableFixtures;

    protected const string BUDGET_EXCEEDED_RESULT = 'AI_BUDGET_REACHED: the account\'s monthly AI budget is used up — this tool cannot run. Do NOT retry this or any '
        . 'other AI-consuming tool. Tell the user plainly that the monthly AI budget is reached and that it '
        . 'resets at the start of next month.';

    protected const string SCOPE_NOT_ELIGIBLE_RESULT = 'AI_SCOPE_NOT_ELIGIBLE: automated work is not permitted for this subscription or account right now — '
        . 'this tool cannot run. Do NOT retry this or any other AI-consuming tool.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->addNeutralToolEffectsToTheCatalog();
    }

    protected function tearDown(): void
    {
        $this->restoreTheEffectCatalog();
        parent::tearDown();
    }

    public function testTheExecutionStoresTheResultOfTheTool(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertSame([self::PLAIN_TOOL], $dispatchService->executedToolNames);
        self::assertCallOutcome($callRow, $loopFlags, 'result:' . self::PLAIN_TOOL, AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
        self::assertSame([], $dispatchService->loggedFailures, 'a failure of the execution is the executor\'s to log');
    }

    public function testAnErrorResultIsAFailedCallThatKeepsTheTools(): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->nextToolResult = new ToolResult('The storage answered 500', true);

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, 'The storage answered 500', AIConversationMessage::STATUS_FAILED, self::NO_LOOP_FLAGS);
        self::assertSame([], $dispatchService->loggedFailures);
    }

    /** @return array<string, array{0: bool, 1: array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool}, 2: string}> */
    public static function ownerGateResults(): array
    {
        return [
            'a refusal of an owner gate' => [true, self::TOOL_LESS_STEP_ONLY, AIConversationMessage::STATUS_FAILED],
            'the same words in a successful result' => [false, self::NO_LOOP_FLAGS, AIConversationMessage::STATUS_COMPLETED],
        ];
    }

    /** @param array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool} $expectedLoopFlags */
    #[DataProvider('ownerGateResults')]
    public function testARefusalOfAnOwnerGateLeavesTheNextStepWithoutTools(bool $isError, array $expectedLoopFlags, string $expectedStatus): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->nextToolResult = new ToolResult('REFUSED — present the draft first', $isError);

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, 'REFUSED — present the draft first', $expectedStatus, $expectedLoopFlags);
    }

    /** @return array<string, array{0: string, 1: bool, 2: array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool}}> */
    public static function purposeEndingResults(): array
    {
        return [
            'the tool that ends the purpose, successful' => ['closeTheWork', false, self::TOOL_LESS_STEP_ONLY],
            'the tool that ends the purpose, an error' => ['closeTheWork', true, self::NO_LOOP_FLAGS],
            'another tool, successful' => [self::PLAIN_TOOL, false, self::NO_LOOP_FLAGS],
        ];
    }

    /**
     * A successful result that ends the purpose of the conversation's tree leaves one move: the closing text.
     *
     * @param array{repeatedToolCallAbort: bool, awaitingDelegates: bool, forceToolLessAnswerStep: bool} $expectedLoopFlags
     */
    #[DataProvider('purposeEndingResults')]
    public function testAResultThatEndsThePurposeLeavesTheNextStepWithoutTools(string $toolName, bool $isError, array $expectedLoopFlags): void
    {
        $purpose = self::instantiateWithoutConstructor(DispatchTablePurposeDouble::class);
        $purpose->purposeEndingToolName = 'closeTheWork';
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, $toolName, [], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->purposeOfConversationTree = $purpose;
        $dispatchService->nextToolResult = new ToolResult('{"structuredContent":{"outcome":"rendered"}}', $isError);

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertSame($expectedLoopFlags, $loopFlags);
        self::assertSame('{"structuredContent":{"outcome":"rendered"}}', $callRow->toolCall->result);
    }

    public function testThePicturesOfASuccessfulResultAreKeptUnderTheCallId(): void
    {
        $toolResult = ToolPictureFixtures::buildToolResultWithPicture(null, 'a picture');
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->nextToolResult = $toolResult;

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, 'a picture', AIConversationMessage::STATUS_COMPLETED, self::NO_LOOP_FLAGS);
        self::assertSame(['call-current' => $toolResult->images], $conversation->getTransientToolCallImages());

        $failedToolResult = ToolPictureFixtures::buildToolResultWithPicture(null, 'a picture');
        $failedToolResult->isError = true;
        $failedConversation = $this->conversation();
        $failedCallRow = $this->appendCallRow($failedConversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
        $failedDispatchService = new DispatchTableDispatchServiceDouble();
        $failedDispatchService->nextToolResult = $failedToolResult;
        $this->dispatch($failedDispatchService, $failedConversation, $failedCallRow);
        self::assertSame([], $failedConversation->getTransientToolCallImages(), 'an error result carries no picture to the wire');
    }

    /** The use of a loaded tool is counted on the call's row when the call completed, never when it failed. */
    public function testTheUseOfTheLoadedToolIsRecordedOnTheCallRow(): void
    {
        foreach ([[false, 1], [true, 0]] as [$isError, $expectedUsageCount]) {
            $conversation = $this->conversation();
            $callRow = $this->appendCallRow($conversation, 'listMandates', [], 'call-current');
            $callRow->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
                'Agent.Mandates' => ['name' => 'Agent.Mandates', 'type' => LoadedCapability::TYPE_SKILL],
                'listMandates' => ['name' => 'listMandates', 'type' => LoadedCapability::TYPE_TOOL, 'parentSkillNames' => ['Agent.Mandates']],
            ]);
            $dispatchService = new DispatchTableDispatchServiceDouble();
            $dispatchService->nextToolResult = new ToolResult('the mandates', $isError);

            $this->dispatch($dispatchService, $conversation, $callRow);

            self::assertSame($expectedUsageCount, $callRow->getLoadedCapabilities()?->getByName('listMandates')?->usageCountSinceLoad, $isError ? 'failed' : 'completed');
        }
    }

    /** @return array<string, array{0: Throwable, 1: string}> what the execution throws, the stored result */
    public static function thrownByTheExecution(): array
    {
        return [
            'the budget' => [new AiBudgetExceededException('budget'), self::BUDGET_EXCEEDED_RESULT],
            'the scope permits no automated work' => [AIScopeNotEligibleException::forEligibilityState('SUSPENDED', null), self::SCOPE_NOT_ELIGIBLE_RESULT],
            'any other error' => [new RuntimeException('boom'), 'Tool error: boom'],
            'the timeout' => [
                new AgentToolExecutionTimeoutException(self::PLAIN_TOOL, 60),
                "Tool error: tool 'probeWorkTool' exceeded the 60s execution timeout and was aborted — the underlying call (likely a stuck external API) did not return.",
            ],
        ];
    }

    #[DataProvider('thrownByTheExecution')]
    public function testAThrowingExecutionIsAFailedCallWhoseTextIsStoredAndLogged(Throwable $thrown, string $expectedResult): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->throwOnExecute = $thrown;

        $loopFlags = $this->dispatch($dispatchService, $conversation, $callRow);

        self::assertCallOutcome($callRow, $loopFlags, $expectedResult, AIConversationMessage::STATUS_FAILED, self::NO_LOOP_FLAGS);
        self::assertSame([['toolName' => self::PLAIN_TOOL, 'error' => $expectedResult, 'exceptionClass' => $thrown::class]], $dispatchService->loggedFailures);
    }

    /** @return array<string, array{0: Throwable}> */
    public static function exitsOfTheLoop(): array
    {
        return [
            'the message-index lock' => [new MessageIndexLockUnavailableException(910001)],
            'the run claim is lost' => [new AgentRunClaimLostException('claim lost')],
        ];
    }

    /** The two exits of the loop are thrown on: no status, no stored result, nothing logged. */
    #[DataProvider('exitsOfTheLoop')]
    public function testTheExitsOfTheLoopAreThrownOn(Throwable $thrown): void
    {
        $conversation = $this->conversation();
        $callRow = $this->appendCallRow($conversation, self::PLAIN_TOOL, ['n' => 1], 'call-current');
        $dispatchService = new DispatchTableDispatchServiceDouble();
        $dispatchService->throwOnExecute = $thrown;

        try {
            $this->dispatch($dispatchService, $conversation, $callRow);
            self::fail('the exception is thrown on');
        } catch (Throwable $caught) {
            self::assertSame($thrown, $caught);
        }

        self::assertNull($callRow->toolCall->result);
        self::assertNull($callRow->toolCall->status);
        self::assertSame([], $dispatchService->loggedFailures);
        self::assertSame([self::PLAIN_TOOL], $dispatchService->executedToolNames);
    }
}
