<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;
use RuntimeException;

/**
 * The `restore` block of an evaluation case that CHANGES its agent space: the harness prepares the state the case is
 * about, captures what the agent may change, lets the agent run, and puts everything back — so the agent space is
 * never left changed, even when the run or a check throws.
 *
 * The block: `{setup?, readTool?, readArgs?, capture?, writeTool?, writeArgs?, after?}`.
 * - `setup`: one step `{tool, args?, resultContains?, undo?}` or a list of them, in order, before the agent runs
 *   ({@see getSetupCalls()}). A step that answers an error, or a reading step whose result lacks one of its
 *   `resultContains` texts, ends the case as not applicable before the agent runs. A step's `undo` removes what it
 *   created, after the run ({@see AgentEvalHarnessToolCall::buildUndoCall()});
 * - `readTool` + `readArgs` + `capture` (`{<argument of the revert write>: <dotted path into the read's
 *   structuredContent>}`): the read that captures the current values ({@see buildCaptureReadCall()}). A read that
 *   needs `outputFormat: structured` to answer structured content states it in `readArgs`;
 * - `writeTool` + `writeArgs`: the revert write after the run, with the captured values; `writeArgs` is a fixed revert
 *   payload for a nested write a dotted path cannot rebuild, and wins over a captured value of the same key
 *   ({@see buildRevertWriteCall()});
 * - `after`: one step `{tool, args?}` or a list, after the revert write — what makes the restored state consistent
 *   again ({@see getAfterCalls()}).
 *
 * The order the runner keeps (every call through the executor, outside the case's tool block):
 * 1. every setup call: execute, record, report, refuse the case on a failed or premise-less step, keep its undo call;
 * 2. the capture read: execute, record, report ({@see buildCaptureReportLine()}), refuse on a failed read or an
 *    unread id ({@see refuseCaseWhoseCaptureFailed()}), keep {@see captureOriginalValues()};
 *    a throw in 1 or 2 runs the undo calls collected so far, then rethrows;
 * 3. the agent's run;
 * 4. in `finally`: the revert write, the after calls, the undo calls in REVERSE order, then the application's release
 *    of what the harness calls hold.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRestorePlan extends ValueObject
{
    /** @var array<string, mixed> The decoded `restore` block as the case states it */
    public array $restoreBlock = [];

    /** @var AgentEvalHarnessToolCalls The preparation steps, in their order */
    public AgentEvalHarnessToolCalls $setupCalls;

    /** @var string The capture read's tool; empty when nothing is captured */
    public string $captureReadToolName = '';

    /** @var array<string, mixed> The capture read's arguments */
    public array $captureReadArguments = [];

    /** @var AgentEvalToolResultExtractions Per argument of the revert write: the path the value is captured from */
    public AgentEvalToolResultExtractions $captureExtractions;

    /** @var string The revert write's tool; empty when nothing is written back */
    public string $revertWriteToolName = '';

    /** @var array<string, mixed> The fixed revert payload (`writeArgs`) */
    public array $fixedRevertArguments = [];

    /** @var AgentEvalHarnessToolCalls The calls after the revert write, in their order */
    public AgentEvalHarnessToolCalls $afterCalls;

    public function __construct()
    {
        parent::__construct();
        $this->setupCalls = AgentEvalHarnessToolCalls::newInstance();
        $this->captureExtractions = AgentEvalToolResultExtractions::newInstance();
        $this->afterCalls = AgentEvalHarnessToolCalls::newInstance();
    }

    /**
     * The plan of a decoded `restore` block.
     *
     * @param array<string, mixed> $restoreBlock
     */
    public static function fromRestoreBlock(array $restoreBlock): static
    {
        $agentEvalRestorePlan = static::newInstance();
        $agentEvalRestorePlan->restoreBlock = $restoreBlock;
        $agentEvalRestorePlan->setupCalls = AgentEvalHarnessToolCall::buildCallsOfStepsBlock(AgentEvalHarnessToolCall::STEP_SETUP, $restoreBlock['setup'] ?? null);
        $agentEvalRestorePlan->captureReadToolName = (string)($restoreBlock['readTool'] ?? '');
        $agentEvalRestorePlan->captureReadArguments = is_array($restoreBlock['readArgs'] ?? null) ? $restoreBlock['readArgs'] : [];
        $capturePathsByArgument = is_array($restoreBlock['capture'] ?? null) ? $restoreBlock['capture'] : [];
        foreach ($capturePathsByArgument as $revertArgumentKey => $capturePath) {
            $captureExtraction = AgentEvalToolResultExtraction::forStructuredContentPath((string)$capturePath, (string)$revertArgumentKey);
            $agentEvalRestorePlan->captureExtractions->add($captureExtraction);
        }
        $agentEvalRestorePlan->revertWriteToolName = (string)($restoreBlock['writeTool'] ?? '');
        $agentEvalRestorePlan->fixedRevertArguments = is_array($restoreBlock['writeArgs'] ?? null) ? $restoreBlock['writeArgs'] : [];
        $agentEvalRestorePlan->afterCalls = AgentEvalHarnessToolCall::buildCallsOfStepsBlock(AgentEvalHarnessToolCall::STEP_AFTER, $restoreBlock['after'] ?? null);
        return $agentEvalRestorePlan;
    }

    /**
     * The block as the case states it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->restoreBlock;
    }

    /**
     * The preparation steps, in their order. Each object is fresh per plan: the runner records the results on them.
     *
     * @return AgentEvalHarnessToolCalls
     */
    public function getSetupCalls(): AgentEvalHarnessToolCalls
    {
        return $this->setupCalls;
    }

    /**
     * The calls after the revert write, in their order.
     *
     * @return AgentEvalHarnessToolCalls
     */
    public function getAfterCalls(): AgentEvalHarnessToolCalls
    {
        return $this->afterCalls;
    }

    /** The capture read; null when the block names no read tool or captures nothing (a fixed revert payload only). */
    public function buildCaptureReadCall(): ?AgentEvalHarnessToolCall
    {
        if ($this->captureReadToolName === '' || $this->captureExtractions->isEmpty()) {
            return null;
        }
        return AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_CAPTURE, $this->captureReadToolName, $this->captureReadArguments);
    }

    /**
     * The captured values out of the recorded capture read, by argument of the revert write (null where the path
     * found nothing).
     *
     * @return array<string, mixed>
     */
    public function captureOriginalValues(AgentEvalHarnessToolCall $captureReadCall): array
    {
        $capturedValues = [];
        foreach ($this->captureExtractions->getElements() as $captureExtraction) {
            $capturedValues[$captureExtraction->argumentKey] = $captureExtraction->resolveValueFromToolResult($captureReadCall->getResultContent());
        }
        return $capturedValues;
    }

    /**
     * The captured keys that name a record (`id` or `…Id`) and came back without a value: the revert write would
     * reach no record, or create one.
     *
     * @param array<string, mixed> $capturedValues
     * @return string[]
     */
    public function findCapturedIdKeysWithoutValue(array $capturedValues): array
    {
        $unreadIdKeys = [];
        foreach ($capturedValues as $capturedKey => $capturedValue) {
            if ($capturedValue === null && preg_match('/(^id|Id)$/', (string)$capturedKey) === 1) {
                $unreadIdKeys[] = (string)$capturedKey;
            }
        }
        return $unreadIdKeys;
    }

    /**
     * The report line of the recorded capture read: the read's answer marked as an error when it failed, the unread
     * ids marked as an error, else the captured values as JSON.
     */
    public function buildCaptureReportLine(AgentEvalHarnessToolCall $captureReadCall): string
    {
        if ($captureReadCall->resultIsError) {
            return $captureReadCall->buildReportLineWithDetail($captureReadCall->getResultContent(), true);
        }
        $capturedValues = $this->captureOriginalValues($captureReadCall);
        $unreadIdKeys = $this->findCapturedIdKeysWithoutValue($capturedValues);
        if ($unreadIdKeys !== []) {
            return $captureReadCall->buildReportLineWithDetail('no value for ' . implode(', ', $unreadIdKeys) . ' — ' . $captureReadCall->getResultContent(), true);
        }
        return $captureReadCall->buildReportLineWithDetail((string)json_encode($capturedValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
    }

    /**
     * Ends the case before the agent runs when the capture read failed or left an id unread: without the originals
     * the revert write would write nulls, so the agent space stays as it is and the case is not run.
     *
     * @throws RuntimeException
     */
    public function refuseCaseWhoseCaptureFailed(AgentEvalHarnessToolCall $captureReadCall): void
    {
        if ($captureReadCall->resultIsError) {
            throw new RuntimeException("The capture read '{$captureReadCall->toolName}' failed, the case was not run: " . mb_substr($captureReadCall->getResultContent(), 0, 300));
        }
        $unreadIdKeys = $this->findCapturedIdKeysWithoutValue($this->captureOriginalValues($captureReadCall));
        if ($unreadIdKeys !== []) {
            throw new RuntimeException("The capture read '{$captureReadCall->toolName}' found no " . implode(', ', $unreadIdKeys) . ', the case was not run.');
        }
    }

    /**
     * The revert write: the fixed payload, completed by the captured values (the fixed payload wins on a key both
     * carry). Null when the block names no write tool or there is nothing to write.
     *
     * @param array<string, mixed> $capturedValues from {@see captureOriginalValues()}; empty when nothing was captured
     */
    public function buildRevertWriteCall(array $capturedValues): ?AgentEvalHarnessToolCall
    {
        $revertArguments = $this->fixedRevertArguments + $capturedValues;
        if ($this->revertWriteToolName === '' || $revertArguments === []) {
            return null;
        }
        return AgentEvalHarnessToolCall::forTool(AgentEvalHarnessToolCall::STEP_WRITE, $this->revertWriteToolName, $revertArguments);
    }
}
