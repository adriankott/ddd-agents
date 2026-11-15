<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * The state check after an evaluation run (`expect.stateAfterRun`): the harness reads back the record the agent wrote,
 * through a read tool with structured output, and checks field predicates on it mechanically — no model judges it, so
 * a weak model cannot talk its way past a wrong stored value.
 *
 * The block:
 * ```
 * { "readTool": "getNote",
 *   "args": {…fixed arguments…},
 *   "argsFromToolResult": { "noteId": {"tool": "saveNote", "regex": "\\[#(\\d+)", "requireArgsAbsent": ["noteId"]} },
 *   "fields": { "note.content": {"maxLength": 280, "notMatches": "https?://", "matches": "…", "equals": "…"} },
 *   "cleanup": { "writeTool": "deleteNote", "args": {…}, "argsFromToolResult": { "noteId": {…} } } }
 * ```
 * `argsFromToolResult` reads a value out of the AGENT'S OWN calls of the run ({@see AgentEvalToolResultExtraction}).
 * `cleanup` removes what the agent created — the teardown a capture before the run cannot cover, because the created
 * id does not exist yet then. It always runs, also when the read is not made or a predicate throws.
 *
 * The order the runner keeps: {@see hasRead()} — none: no failure, still the cleanup; resolve the read's arguments
 * ({@see AgentEvalHarnessToolCall::resolveArgumentsFromToolCallsOf()}); an unread argument is one failure and no read
 * ({@see buildUnresolvedArgumentFailures()}); else execute {@see buildReadCall()}, record it and take
 * {@see evaluateReadResult()}; in `finally` {@see buildCleanupCall()}, execute it when there is one, report its
 * {@see AgentEvalHarnessToolCall::buildReportLine()}.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalStateAfterRunCheck extends ValueObject
{
    /** @var string Predicate: the value equals the argument (loose comparison, a number matches its digits) */
    public const string PREDICATE_EQUALS = 'equals';

    /** @var string Predicate: the value is a scalar of at most this many characters */
    public const string PREDICATE_MAX_LENGTH = 'maxLength';

    /** @var string Predicate: the value is a scalar the pattern finds, case-insensitively */
    public const string PREDICATE_MATCHES = 'matches';

    /** @var string Predicate: the value is no scalar, or one the pattern does not find, case-insensitively */
    public const string PREDICATE_NOT_MATCHES = 'notMatches';

    /** @var string[] The predicates whose argument is a pattern */
    public const array PATTERN_PREDICATES = [self::PREDICATE_MATCHES, self::PREDICATE_NOT_MATCHES];

    /** @var array<string, mixed> The decoded `stateAfterRun` block as the case states it */
    public array $stateAfterRunBlock = [];

    /** @var AgentEvalHarnessToolCall|null The read, before its arguments are read from the agent's calls; null when the block names no read tool */
    public ?AgentEvalHarnessToolCall $readCallTemplate = null;

    /** @var array<string, array<string, mixed>> The predicates by dotted path into the read's structured content */
    public array $fieldPredicates = [];

    /** @var AgentEvalHarnessToolCall|null The cleanup, before its arguments are read from the agent's calls */
    public ?AgentEvalHarnessToolCall $cleanupCallTemplate = null;

    /**
     * The check of a decoded `expect.stateAfterRun` block.
     *
     * @param array<string, mixed> $stateAfterRunBlock
     */
    public static function fromStateAfterRunBlock(array $stateAfterRunBlock): static
    {
        $agentEvalStateAfterRunCheck = static::newInstance();
        $agentEvalStateAfterRunCheck->stateAfterRunBlock = $stateAfterRunBlock;
        $readToolName = (string)($stateAfterRunBlock['readTool'] ?? '');
        if ($readToolName !== '') {
            $readCallTemplate = AgentEvalHarnessToolCall::forTool(
                AgentEvalHarnessToolCall::STEP_STATE_READ,
                $readToolName,
                is_array($stateAfterRunBlock['args'] ?? null) ? $stateAfterRunBlock['args'] : [],
                true
            );
            $readCallTemplate->argumentExtractions = $readCallTemplate->buildArgumentExtractions($stateAfterRunBlock['argsFromToolResult'] ?? null);
            $agentEvalStateAfterRunCheck->readCallTemplate = $readCallTemplate;
        }
        $agentEvalStateAfterRunCheck->fieldPredicates = is_array($stateAfterRunBlock['fields'] ?? null) ? $stateAfterRunBlock['fields'] : [];
        $cleanupBlock = is_array($stateAfterRunBlock['cleanup'] ?? null) ? $stateAfterRunBlock['cleanup'] : null;
        if ($cleanupBlock !== null && isset($cleanupBlock['writeTool'])) {
            $cleanupCallTemplate = AgentEvalHarnessToolCall::forTool(
                AgentEvalHarnessToolCall::STEP_CLEANUP,
                (string)$cleanupBlock['writeTool'],
                is_array($cleanupBlock['args'] ?? null) ? $cleanupBlock['args'] : [],
                true
            );
            $cleanupCallTemplate->argumentExtractions = $cleanupCallTemplate->buildArgumentExtractions($cleanupBlock['argsFromToolResult'] ?? null);
            $agentEvalStateAfterRunCheck->cleanupCallTemplate = $cleanupCallTemplate;
        }
        return $agentEvalStateAfterRunCheck;
    }

    /**
     * The block as the case states it.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->stateAfterRunBlock;
    }

    /** Whether the block names a read tool; without one the check finds no failure (the cleanup still runs). */
    public function hasRead(): bool
    {
        return $this->readCallTemplate !== null;
    }

    /**
     * The read's arguments out of the agent's own calls (argument key → value, null when unread), in block order.
     *
     * @return array<string, mixed>
     */
    public function resolveReadArgumentsFromToolCallsOf(AIConversationMessages $messages): array
    {
        return $this->readCallTemplate?->resolveArgumentsFromToolCallsOf($messages) ?? [];
    }

    /**
     * The failure of a read whose argument could not be read from the agent's calls: one sentence for the FIRST
     * unread argument — the read is then not made. Empty when every argument was read.
     *
     * @param array<string, mixed> $resolvedReadArguments from {@see resolveReadArgumentsFromToolCallsOf()}
     * @return string[]
     */
    public function buildUnresolvedArgumentFailures(array $resolvedReadArguments): array
    {
        foreach ($resolvedReadArguments as $argumentKey => $argumentValue) {
            if ($argumentValue === null) {
                return ["stateAfterRun: could not resolve '{$argumentKey}' from the agent's tool results"];
            }
        }
        return [];
    }

    /**
     * The read with the arguments read from the agent's calls on top of the fixed ones; null without a read tool.
     *
     * @param array<string, mixed> $resolvedReadArguments from {@see resolveReadArgumentsFromToolCallsOf()}, all read
     */
    public function buildReadCall(array $resolvedReadArguments): ?AgentEvalHarnessToolCall
    {
        return $this->readCallTemplate?->buildCallWithResolvedArguments($resolvedReadArguments);
    }

    /**
     * The failures of the field predicates over the recorded read's structured content.
     *
     * @return string[]
     */
    public function evaluateReadResult(AgentEvalHarnessToolCall $readCall): array
    {
        return $this->evaluateFieldPredicates($readCall->getResultStructuredContent());
    }

    /**
     * The failures of the field predicates over a structured read result: per dotted path `equals`, `maxLength`
     * (characters), `matches` / `notMatches` (a pattern without delimiters, case-insensitive). An unknown predicate
     * fails. A pattern that does not compile fails its predicate with its own sentence
     * ({@see buildInvalidPatternFailure()}), `notMatches` included: an invalid pattern never lets a value pass
     * unchecked.
     *
     * @return string[] failure sentences; empty when the state matches
     */
    public function evaluateFieldPredicates(mixed $structuredContent): array
    {
        $failures = [];
        foreach ($this->fieldPredicates as $fieldPath => $predicates) {
            $fieldExtraction = AgentEvalToolResultExtraction::forStructuredContentPath((string)$fieldPath);
            $fieldValue = $fieldExtraction->resolveValueFromStructuredContent($structuredContent);
            foreach ((array)$predicates as $predicateName => $predicateArgument) {
                if ($this->hasInvalidPattern($fieldExtraction, (string)$predicateName, $predicateArgument)) {
                    $failures[] = $this->buildInvalidPatternFailure((string)$fieldPath, (string)$predicateName, $predicateArgument);
                    continue;
                }
                if ($this->failsPredicate($fieldExtraction, $fieldValue, (string)$predicateName, $predicateArgument)) {
                    $failures[] = $this->buildPredicateFailure((string)$fieldPath, (string)$predicateName, $predicateArgument, $fieldValue);
                }
            }
        }
        return $failures;
    }

    /** Whether the field's value fails one predicate. */
    public function failsPredicate(AgentEvalToolResultExtraction $fieldExtraction, mixed $fieldValue, string $predicateName, mixed $predicateArgument): bool
    {
        $stringValue = is_scalar($fieldValue) ? (string)$fieldValue : null;
        return match ($predicateName) {
            static::PREDICATE_EQUALS => $fieldValue != $predicateArgument,
            static::PREDICATE_MAX_LENGTH => $stringValue === null || mb_strlen($stringValue) > (int)$predicateArgument,
            static::PREDICATE_MATCHES => $stringValue === null
                || preg_match($fieldExtraction->buildDelimitedPattern((string)$predicateArgument, 'i'), $stringValue) !== 1,
            static::PREDICATE_NOT_MATCHES => $stringValue !== null
                && preg_match($fieldExtraction->buildDelimitedPattern((string)$predicateArgument, 'i'), $stringValue) === 1,
            default => true,
        };
    }

    /** Whether the predicate takes a pattern ({@see PATTERN_PREDICATES}) and its pattern does not compile. */
    public function hasInvalidPattern(AgentEvalToolResultExtraction $fieldExtraction, string $predicateName, mixed $predicateArgument): bool
    {
        return in_array($predicateName, static::PATTERN_PREDICATES, true)
            && !$fieldExtraction->isValidPattern(is_scalar($predicateArgument) ? (string)$predicateArgument : '', 'i');
    }

    /**
     * The failure sentence of a pattern predicate whose pattern does not compile:
     * `stateAfterRun[<path>].<predicate>(<pattern>) failed — invalid pattern, the value was not checked`.
     */
    public function buildInvalidPatternFailure(string $fieldPath, string $predicateName, mixed $predicateArgument): string
    {
        return sprintf(
            'stateAfterRun[%s].%s(%s) failed — invalid pattern, the value was not checked',
            $fieldPath,
            $predicateName,
            is_scalar($predicateArgument) ? (string)$predicateArgument : json_encode($predicateArgument)
        );
    }

    /** The failure sentence of one predicate: `stateAfterRun[<path>].<predicate>(<argument>) failed — value: …`. */
    public function buildPredicateFailure(string $fieldPath, string $predicateName, mixed $predicateArgument, mixed $fieldValue): string
    {
        $stringValue = is_scalar($fieldValue) ? (string)$fieldValue : null;
        return sprintf(
            "stateAfterRun[%s].%s(%s) failed — value: %s",
            $fieldPath,
            $predicateName,
            is_scalar($predicateArgument) ? (string)$predicateArgument : json_encode($predicateArgument),
            $stringValue !== null ? '"' . mb_substr($stringValue, 0, 120) . '" (' . mb_strlen($stringValue) . ' chars)' : json_encode($fieldValue)
        );
    }

    /**
     * The cleanup with the arguments read from the agent's calls on top of the fixed ones (an unread one is left out).
     * Null when the block names no cleanup or the cleanup carries no argument at all: a removal without a target is
     * not made.
     */
    public function buildCleanupCall(AIConversationMessages $messages): ?AgentEvalHarnessToolCall
    {
        if ($this->cleanupCallTemplate === null) {
            return null;
        }
        $cleanupCall = $this->cleanupCallTemplate->buildCallWithResolvedArguments($this->cleanupCallTemplate->resolveArgumentsFromToolCallsOf($messages));
        return $cleanupCall->hasArguments() ? $cleanupCall : null;
    }
}
