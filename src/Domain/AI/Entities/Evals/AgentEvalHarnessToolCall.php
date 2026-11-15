<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Exceptions\Evals\AgentEvalPreconditionNotEstablishedException;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * ONE tool call the evaluation harness makes itself, never the agent: a preparation step of a restore block, the
 * capture read, the revert write, an `after` step, the undo of a preparation step, the read of a state check and its
 * cleanup. The object is pure: it says which tool is called with which arguments, where an argument is read from a
 * tool result, and what the result means once the caller recorded it ({@see recordResult()}). The call itself runs in
 * {@see \DDD\Domain\AI\Services\Eval\AgentEvalRunnerService}: through the tool executor with the arguments of
 * {@see buildExecutedArguments()}, the harness provenance of the stored agent space, and the case's own tool block
 * lifted around it (the block guards the agent, not the harness).
 *
 * A step block: `{tool, args?, resultContains?, undo?}`; `undo`: `{tool, args?, argsFromResult?}`, whose
 * `argsFromResult` (argument → {@see AgentEvalToolResultExtraction} block) is read from the result of the preparation
 * step itself ({@see buildUndoCall()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalHarnessToolCall extends ValueObject
{
    /** @var string A preparation step (`restore.setup`), before the agent runs */
    public const string STEP_SETUP = 'setup';

    /** @var string The read that captures the values the revert write puts back (`restore.readTool`) */
    public const string STEP_CAPTURE = 'capture';

    /** @var string The revert write after the run (`restore.writeTool`) */
    public const string STEP_WRITE = 'write';

    /** @var string A call after the revert write (`restore.after`) */
    public const string STEP_AFTER = 'after';

    /** @var string The removal of what a preparation step created (`restore.setup[].undo`) */
    public const string STEP_UNDO = 'undo';

    /** @var string The read of a state check after the run (`expect.stateAfterRun.readTool`) */
    public const string STEP_STATE_READ = 'state read';

    /** @var string The removal of what the agent created, after a state check (`expect.stateAfterRun.cleanup`) */
    public const string STEP_CLEANUP = 'cleanup';

    /** @var string The prefix of every report line of a harness call */
    public const string REPORT_LINE_PREFIX = 'restore ';

    /** @var string The marker of a report line whose call failed; a suite reads the marker, never the cut text */
    public const string REPORT_LINE_ERROR_MARKER = '[ERROR] ';

    /** @var int The length of the detail of a report line */
    public const int REPORT_LINE_DETAIL_MAX_LENGTH = 400;

    /** @var int The length of a tool message quoted in a refusal of the case */
    public const int REFUSAL_TOOL_MESSAGE_MAX_LENGTH = 300;

    /** @var string One of the `STEP_*` constants */
    public string $step = '';

    /** @var string The tool that is called */
    public string $toolName = '';

    /** @var array<string, mixed> The arguments the case states for the call */
    public array $arguments = [];

    /** @var bool Whether the call asks for the structured output (a read whose fields are checked, a removal whose `deleted` flag is read) */
    public bool $asksForStructuredOutput = false;

    /** @var string[] Texts the result of a preparation step must show (`resultContains`): the premise of the case */
    public array $expectedResultTexts = [];

    /** @var AgentEvalToolResultExtractions Arguments still to be read from a tool result, one per argument key */
    public AgentEvalToolResultExtractions $argumentExtractions;

    /** @var AgentEvalHarnessToolCall|null The removal of what this preparation step creates, before its arguments are read from the result */
    public ?AgentEvalHarnessToolCall $undoCallTemplate = null;

    /** @var string The word for the agent space in a refusal sentence ({@see AgentApplicationWording::$agentSpaceName}) */
    public string $agentSpaceName = '';

    /** @var string|null The result content the executor answered; null until {@see recordResult()} */
    public ?string $resultContent = null;

    /** @var bool Whether the executor answered an error */
    public bool $resultIsError = false;

    public function __construct()
    {
        parent::__construct();
        $this->argumentExtractions = AgentEvalToolResultExtractions::newInstance();
    }

    /**
     * A call of the tool with the arguments as stated.
     *
     * @param string $step one of the `STEP_*` constants
     * @param array<string, mixed> $arguments
     */
    public static function forTool(string $step, string $toolName, array $arguments = [], bool $asksForStructuredOutput = false): static
    {
        $agentEvalHarnessToolCall = static::newInstance();
        $agentEvalHarnessToolCall->step = $step;
        $agentEvalHarnessToolCall->toolName = $toolName;
        $agentEvalHarnessToolCall->arguments = $arguments;
        $agentEvalHarnessToolCall->asksForStructuredOutput = $asksForStructuredOutput;
        $agentEvalHarnessToolCall->agentSpaceName = AgentApplicationWording::current()->agentSpaceName;
        return $agentEvalHarnessToolCall;
    }

    /**
     * The call of one step block `{tool, args?, resultContains?, undo?}` (a preparation or an `after` step); null for a
     * block that is no map or names no tool — such a block is skipped.
     *
     * @param string $step {@see STEP_SETUP} or {@see STEP_AFTER}
     * @param mixed $stepBlock the decoded block as the case states it
     */
    public static function fromStepBlock(string $step, mixed $stepBlock): ?static
    {
        if (!is_array($stepBlock) || !isset($stepBlock['tool'])) {
            return null;
        }
        $agentEvalHarnessToolCall = static::forTool($step, (string)$stepBlock['tool'], is_array($stepBlock['args'] ?? null) ? $stepBlock['args'] : []);
        $agentEvalHarnessToolCall->expectedResultTexts = is_array($stepBlock['resultContains'] ?? null)
            ? array_values(array_map('strval', $stepBlock['resultContains']))
            : [];
        $undoBlock = is_array($stepBlock['undo'] ?? null) ? $stepBlock['undo'] : null;
        if ($undoBlock !== null && isset($undoBlock['tool'])) {
            $undoCallTemplate = static::forTool(static::STEP_UNDO, (string)$undoBlock['tool'], is_array($undoBlock['args'] ?? null) ? $undoBlock['args'] : [], true);
            $undoCallTemplate->argumentExtractions = $undoCallTemplate->buildArgumentExtractions($undoBlock['argsFromResult'] ?? null);
            $agentEvalHarnessToolCall->undoCallTemplate = $undoCallTemplate;
        }
        return $agentEvalHarnessToolCall;
    }

    /**
     * The calls of a block that holds one step (`{tool, …}`) or a list of steps, in their order; entries that name no
     * tool are left out.
     *
     * @param string $step {@see STEP_SETUP} or {@see STEP_AFTER}
     * @param mixed $stepsBlock the decoded block as the case states it; null or no map gives no call
     * @return AgentEvalHarnessToolCalls
     */
    public static function buildCallsOfStepsBlock(string $step, mixed $stepsBlock): AgentEvalHarnessToolCalls
    {
        $agentEvalHarnessToolCalls = AgentEvalHarnessToolCalls::newInstance();
        if (!is_array($stepsBlock)) {
            return $agentEvalHarnessToolCalls;
        }
        $stepBlocks = isset($stepsBlock['tool']) ? [$stepsBlock] : $stepsBlock;
        foreach ($stepBlocks as $stepBlock) {
            $agentEvalHarnessToolCall = static::fromStepBlock($step, $stepBlock);
            if ($agentEvalHarnessToolCall !== null) {
                $agentEvalHarnessToolCalls->add($agentEvalHarnessToolCall);
            }
        }
        return $agentEvalHarnessToolCalls;
    }

    /**
     * The extractions of an `argsFromResult` / `argsFromToolResult` block (argument → extraction block), each naming
     * its argument, in the order of the block; an empty set for a block that is no map.
     *
     * @return AgentEvalToolResultExtractions
     */
    public function buildArgumentExtractions(mixed $argumentExtractionsBlock): AgentEvalToolResultExtractions
    {
        $argumentExtractions = AgentEvalToolResultExtractions::newInstance();
        if (!is_array($argumentExtractionsBlock)) {
            return $argumentExtractions;
        }
        foreach ($argumentExtractionsBlock as $argumentKey => $extractionBlock) {
            $argumentExtraction = AgentEvalToolResultExtraction::fromExtractionBlock($extractionBlock, (string)$argumentKey);
            $argumentExtractions->add($argumentExtraction);
        }
        return $argumentExtractions;
    }

    /**
     * The call as the logs and callers have always held it.
     *
     * @return array{tool: string, args: array<string, mixed>}
     */
    public function toArray(): array
    {
        return ['tool' => $this->toolName, 'args' => $this->arguments];
    }

    /**
     * The arguments the executor receives: first the arguments the application fills on every call of the run (the
     * agent space's own argument, when its tools take one), then the structured-output switch when the call asks for
     * it, then the arguments of the case. A key filled by the application is never overwritten by the case.
     *
     * @param array<string, mixed> $argumentsFilledByThePlatform
     * @return array<string, mixed>
     */
    public function buildExecutedArguments(array $argumentsFilledByThePlatform = []): array
    {
        $outputFormatArguments = $this->asksForStructuredOutput ? ['outputFormat' => RequestDto::OUTPUT_FORMAT_STRUCTURED] : [];
        return $argumentsFilledByThePlatform + $outputFormatArguments + $this->arguments;
    }

    /**
     * The values of {@see $argumentExtractions} read from the agent's own calls, in the order of the block.
     *
     * @param AIConversationMessages $messages the driven conversation's rows, delegation children included
     * @return array<string, mixed> argument key → value, null when it could not be read
     */
    public function resolveArgumentsFromToolCallsOf(AIConversationMessages $messages): array
    {
        $resolvedArguments = [];
        foreach ($this->argumentExtractions->getElements() as $argumentExtraction) {
            $resolvedArguments[$argumentExtraction->argumentKey] = $argumentExtraction->resolveValueFromToolCallsOf($messages);
        }
        return $resolvedArguments;
    }

    /**
     * A copy of the call that carries the read values on top of its stated arguments: a value that could not be read
     * (null) is left out, and no extraction remains.
     *
     * @param array<string, mixed> $resolvedArguments argument key → value or null
     */
    public function buildCallWithResolvedArguments(array $resolvedArguments): static
    {
        $resolvedCall = static::forTool($this->step, $this->toolName, $this->arguments, $this->asksForStructuredOutput);
        $resolvedCall->agentSpaceName = $this->agentSpaceName;
        foreach ($resolvedArguments as $argumentKey => $argumentValue) {
            if ($argumentValue !== null) {
                $resolvedCall->arguments[(string)$argumentKey] = $argumentValue;
            }
        }
        return $resolvedCall;
    }

    /** Whether the call carries at least one argument of the case (a removal without its target is not made). */
    public function hasArguments(): bool
    {
        return $this->arguments !== [];
    }

    /** Records what the executor answered; every reading of the result below needs it. */
    public function recordResult(string $resultContent, bool $resultIsError): void
    {
        $this->resultContent = $resultContent;
        $this->resultIsError = $resultIsError;
    }

    /** The recorded result content; empty before {@see recordResult()}. */
    public function getResultContent(): string
    {
        return $this->resultContent ?? '';
    }

    /**
     * The text of the FIRST content part of the result JSON, untrimmed; null when the result is no JSON object or that
     * part carries no text.
     */
    public function getFirstContentTextOfResult(): ?string
    {
        $decodedResult = json_decode($this->getResultContent(), true);
        $firstContentText = is_array($decodedResult) ? ($decodedResult['content'][0]['text'] ?? null) : null;
        return is_string($firstContentText) ? $firstContentText : null;
    }

    /** The `structuredContent` of the recorded result JSON; null when the result is no JSON object or carries none. */
    public function getResultStructuredContent(): mixed
    {
        $decodedResult = json_decode($this->getResultContent(), true);
        return is_array($decodedResult) ? ($decodedResult['structuredContent'] ?? null) : null;
    }

    /**
     * The removal of what this preparation step created, its arguments read from this step's recorded result. Null
     * when the step names no undo or its result does not carry a value the undo needs (the step failed: nothing to
     * undo).
     */
    public function buildUndoCall(): ?static
    {
        if ($this->undoCallTemplate === null) {
            return null;
        }
        $undoArguments = $this->undoCallTemplate->arguments;
        foreach ($this->undoCallTemplate->argumentExtractions->getElements() as $argumentExtraction) {
            $argumentValue = $argumentExtraction->resolveValueFromToolResult($this->getResultContent());
            if ($argumentValue === null) {
                return null;
            }
            $undoArguments[$argumentExtraction->argumentKey] = $argumentValue;
        }
        $undoCall = static::forTool(static::STEP_UNDO, $this->undoCallTemplate->toolName, $undoArguments, true);
        $undoCall->agentSpaceName = $this->agentSpaceName;
        return $undoCall;
    }

    /**
     * The log text of a removal by the harness. An error answer there means that the record was gone already (the
     * agent removed it), so the text carries the tool's message and not the error flag of the raw result.
     */
    public function describeRemovalResult(): string
    {
        if (!$this->resultIsError) {
            return $this->getResultContent();
        }
        $toolMessage = $this->getFirstContentTextOfResult();
        return '(nothing left to remove, or refused) ' . ($toolMessage ?? 'the tool answered an error');
    }

    /**
     * A removal whose structured result says that the record is still there (`deleted: false`, strictly — a record
     * that stays live on an outside platform it cannot be taken down from). An error answer is no such result: the
     * record was gone.
     */
    public function isRemovalThatLeftTheObject(): bool
    {
        $structuredContent = $this->getResultStructuredContent();
        return is_array($structuredContent) && ($structuredContent['deleted'] ?? null) === false;
    }

    /** Whether the call removes a record (an undo or a cleanup): its report line reads the result as a removal. */
    public function isRemoval(): bool
    {
        return $this->step === static::STEP_UNDO || $this->step === static::STEP_CLEANUP;
    }

    /**
     * The report line of the recorded call, without indentation: `restore <step> <tool>: [ERROR] <detail>`. A removal
     * reports {@see describeRemovalResult()} and is marked only when it left its record; every other call reports its
     * result and is marked when it answered an error.
     */
    public function buildReportLine(): string
    {
        if ($this->isRemoval()) {
            return $this->buildReportLineWithDetail($this->describeRemovalResult(), $this->isRemovalThatLeftTheObject());
        }
        return $this->buildReportLineWithDetail($this->getResultContent(), $this->resultIsError);
    }

    /**
     * A report line of this call with a detail of the caller's (the capture reports what it captured): whitespace
     * runs become one space, the detail is cut to {@see REPORT_LINE_DETAIL_MAX_LENGTH} characters.
     */
    public function buildReportLineWithDetail(string $detail, bool $isError): string
    {
        return static::REPORT_LINE_PREFIX . $this->step . ' ' . $this->toolName . ': '
            . ($isError ? static::REPORT_LINE_ERROR_MARKER : '')
            . mb_substr(preg_replace('/\s+/', ' ', $detail) ?? $detail, 0, static::REPORT_LINE_DETAIL_MAX_LENGTH);
    }

    /**
     * Ends the case before the agent runs when this preparation step answered an error: the state the case is about
     * was not established, and what the agent does without it says nothing about the case.
     *
     * @throws AgentEvalPreconditionNotEstablishedException
     */
    public function refuseCaseWhosePreparationStepFailed(): void
    {
        if (!$this->resultIsError) {
            return;
        }
        throw new AgentEvalPreconditionNotEstablishedException(sprintf(
            "the preparation step '%s' of the case failed, the agent was not run: %s",
            $this->toolName,
            mb_substr($this->getFirstContentTextOfResult() ?? $this->getResultContent(), 0, static::REFUSAL_TOOL_MESSAGE_MAX_LENGTH)
        ));
    }

    /**
     * Ends the case before the agent runs when this preparation step READS the agent space and its result does not
     * show one of {@see $expectedResultTexts}: the agent space lost the state the case is about.
     *
     * @throws AgentEvalPreconditionNotEstablishedException
     */
    public function refuseCaseWhosePreparationStepLacksExpectedResult(): void
    {
        if ($this->expectedResultTexts === []) {
            return;
        }
        $resultText = $this->getFirstContentTextOfResult() ?? $this->getResultContent();
        foreach ($this->expectedResultTexts as $expectedResultText) {
            if (!str_contains($resultText, $expectedResultText)) {
                throw new AgentEvalPreconditionNotEstablishedException(sprintf(
                    "the preparation step '%s' of the case does not show '%s' on this %s, the agent was not run",
                    $this->toolName,
                    $expectedResultText,
                    $this->agentSpaceName
                ));
            }
        }
    }
}
