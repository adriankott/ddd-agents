<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * How the evaluation harness reads ONE value out of a tool result: an entry of `argsFromResult` (the undo of a
 * preparation step reads the step's own result), of `argsFromToolResult` (a state check or its cleanup reads the
 * result of a call the AGENT made during the run), a `capture` path of a restore block or a field path of a state
 * check.
 *
 * The block: `{tool?, path?, regex?, requireArgsAbsent?}`.
 * - `path` walks the `structuredContent` of the result JSON by a dotted path ({@see extractByPath()});
 * - `regex` (capture group 1) scans the raw result text when the path found nothing or none is given — a result the
 *   agent read as rendered text carries an id only there (e.g. `[#123 · NOTE …]`). The pattern is written WITHOUT
 *   delimiters; a `/` in it needs no escaping ({@see buildDelimitedPattern()});
 * - `tool` names the agent's call to read from: the LAST COMPLETED call of that tool wins, the agent may retry
 *   ({@see getResultOfLastMatchingToolCall()});
 * - `requireArgsAbsent` (a list of argument keys) leaves out every call whose arguments carry one of the keys with a
 *   value: a cleanup that removes the record the agent CREATED must never land on an update of a record that existed
 *   before the run, which carries the record's id ({@see toolCallCarriesAnyArgument()}).
 *
 * An extraction that reads the value of an argument (of an undo, a read, a cleanup or the revert write) names that
 * argument ({@see $argumentKey}) and is held under it in an {@see AgentEvalToolResultExtractions}.
 *
 * The result JSON is decoded here on its own, never through the stored call's decoders: no trailing-hint fallback,
 * so a result with text after its JSON object yields nothing by path (the regex still scans it).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalToolResultExtraction extends ValueObject
{
    /** @var string The argument whose value is read; empty for an extraction that reads no argument (a field of a state check) */
    public string $argumentKey = '';

    /** @var string The tool whose last completed call is read (`tool`); empty when the result is given directly */
    public string $sourceToolName = '';

    /** @var string|null The dotted path into the result's `structuredContent` (`path`) */
    public ?string $structuredContentPath = null;

    /** @var string|null The pattern without delimiters whose capture group 1 is the value (`regex`) */
    public ?string $resultTextPattern = null;

    /** @var string[] Argument keys a call must NOT carry with a value to be read (`requireArgsAbsent`) */
    public array $argumentKeysRequiredAbsent = [];

    /**
     * The extraction of one block `{tool?, path?, regex?, requireArgsAbsent?}`; a block that is no map reads nothing.
     *
     * @param mixed $extractionBlock the decoded block as the case states it
     * @param string $argumentKey the argument whose value the block reads; empty for none
     */
    public static function fromExtractionBlock(mixed $extractionBlock, string $argumentKey = ''): static
    {
        $agentEvalToolResultExtraction = static::newInstance();
        $agentEvalToolResultExtraction->argumentKey = $argumentKey;
        if (!is_array($extractionBlock)) {
            return $agentEvalToolResultExtraction;
        }
        $agentEvalToolResultExtraction->sourceToolName = (string)($extractionBlock['tool'] ?? '');
        $agentEvalToolResultExtraction->structuredContentPath = isset($extractionBlock['path']) ? (string)$extractionBlock['path'] : null;
        $agentEvalToolResultExtraction->resultTextPattern = isset($extractionBlock['regex']) ? (string)$extractionBlock['regex'] : null;
        $agentEvalToolResultExtraction->argumentKeysRequiredAbsent = array_values(array_map('strval', (array)($extractionBlock['requireArgsAbsent'] ?? [])));
        return $agentEvalToolResultExtraction;
    }

    /**
     * The extraction of one dotted path into the `structuredContent` of a result (a `capture` entry, a field of a
     * state check).
     *
     * @param string $structuredContentPath
     * @param string $argumentKey the argument whose value the path reads (a `capture` entry); empty for none
     */
    public static function forStructuredContentPath(string $structuredContentPath, string $argumentKey = ''): static
    {
        $agentEvalToolResultExtraction = static::newInstance();
        $agentEvalToolResultExtraction->argumentKey = $argumentKey;
        $agentEvalToolResultExtraction->structuredContentPath = $structuredContentPath;
        return $agentEvalToolResultExtraction;
    }

    /**
     * @param string $argumentKey
     * @return string the key of the extraction of that argument in an {@see AgentEvalToolResultExtractions}
     */
    public static function uniqueKeyForArgumentKey(string $argumentKey): string
    {
        return self::class . '_' . $argumentKey;
    }

    /**
     * The key of an extraction that reads an argument is its argument key; one that reads no argument keeps the key
     * of its own object.
     */
    public function uniqueKey(): string
    {
        return $this->argumentKey !== '' ? static::uniqueKeyForArgumentKey($this->argumentKey) : parent::uniqueKey();
    }

    /**
     * The block as the case states it, only the keys it carries (the argument key is the block's key, not part of it).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $extractionBlock = [];
        if ($this->sourceToolName !== '') {
            $extractionBlock['tool'] = $this->sourceToolName;
        }
        if ($this->structuredContentPath !== null) {
            $extractionBlock['path'] = $this->structuredContentPath;
        }
        if ($this->resultTextPattern !== null) {
            $extractionBlock['regex'] = $this->resultTextPattern;
        }
        if ($this->argumentKeysRequiredAbsent !== []) {
            $extractionBlock['requireArgsAbsent'] = $this->argumentKeysRequiredAbsent;
        }
        return $extractionBlock;
    }

    /**
     * The value out of one stored tool result: the path first, then the pattern over the raw text. Null when
     * neither finds a value.
     */
    public function resolveValueFromToolResult(string $resultContent): mixed
    {
        $value = null;
        if ($this->structuredContentPath !== null) {
            $value = $this->resolveValueFromStructuredContent($this->decodeStructuredContent($resultContent));
        }
        if ($value === null && $this->resultTextPattern !== null
            && preg_match($this->buildDelimitedPattern($this->resultTextPattern), $resultContent, $patternMatches) === 1
        ) {
            $value = $patternMatches[1] ?? null;
        }
        return $value;
    }

    /**
     * The value at {@see $structuredContentPath} of an already decoded structured content; null without a path or
     * when a segment is missing.
     */
    public function resolveValueFromStructuredContent(mixed $structuredContent): mixed
    {
        return $this->structuredContentPath !== null ? $this->extractByPath($structuredContent, $this->structuredContentPath) : null;
    }

    /**
     * The value out of the agent's own calls: the result of the last matching call ({@see getResultOfLastMatchingToolCall()}),
     * read by {@see resolveValueFromToolResult()}. Null when no call matches or its result carries no value.
     *
     * @param AIConversationMessages $messages the driven conversation's rows, delegation children included
     */
    public function resolveValueFromToolCallsOf(AIConversationMessages $messages): mixed
    {
        $resultContent = $this->getResultOfLastMatchingToolCall($messages);
        return $resultContent !== null ? $this->resolveValueFromToolResult($resultContent) : null;
    }

    /**
     * The stored result of the LAST call of {@see $sourceToolName} that COMPLETED and carries none of
     * {@see $argumentKeysRequiredAbsent}. A later matching call without a stored result answers null.
     */
    public function getResultOfLastMatchingToolCall(AIConversationMessages $messages): ?string
    {
        $resultContent = null;
        foreach ($messages->buildToolCallHistory()->getToolCallRows() as $toolCallMessage) {
            if ((string)$toolCallMessage->toolCall->name !== $this->sourceToolName
                || $toolCallMessage->toolCall->status !== AIConversationMessage::STATUS_COMPLETED
            ) {
                continue;
            }
            if ($this->argumentKeysRequiredAbsent !== []
                && $this->toolCallCarriesAnyArgument($toolCallMessage->toolCall->arguments ?? null, $this->argumentKeysRequiredAbsent)
            ) {
                // an update of a record that existed before, never the target of the read
                continue;
            }
            $storedResult = $toolCallMessage->toolCall->result ?? null;
            $resultContent = $storedResult !== null ? (string)$storedResult : null;
        }
        return $resultContent;
    }

    /**
     * Whether the call's arguments carry ANY of the keys with a value. Null, an empty string, `0`, `'0'` and false
     * count as absent: an id argument of `0` is the shape of a create.
     *
     * @param mixed $arguments the call's decoded arguments
     * @param string[] $argumentKeys
     */
    public function toolCallCarriesAnyArgument(mixed $arguments, array $argumentKeys): bool
    {
        if (!is_array($arguments)) {
            return false;
        }
        foreach ($argumentKeys as $argumentKey) {
            $argumentValue = $arguments[(string)$argumentKey] ?? null;
            if ($argumentValue !== null && $argumentValue !== '' && $argumentValue !== 0 && $argumentValue !== '0' && $argumentValue !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * The `structuredContent` of a result JSON; null when the result is no JSON object or carries none.
     */
    public function decodeStructuredContent(string $resultContent): mixed
    {
        $decodedResult = json_decode($resultContent, true);
        return is_array($decodedResult) ? ($decodedResult['structuredContent'] ?? null) : null;
    }

    /**
     * The value at a dotted path (`record.description.long`) of a nested array; null when a segment is missing.
     */
    public function extractByPath(mixed $nestedValue, string $dottedPath): mixed
    {
        foreach (explode('.', $dottedPath) as $pathSegment) {
            if (!is_array($nestedValue) || !array_key_exists($pathSegment, $nestedValue)) {
                return null;
            }
            $nestedValue = $nestedValue[$pathSegment];
        }
        return $nestedValue;
    }

    /**
     * Whether a fixture's pattern body compiles once it stands between `/` delimiters ({@see buildDelimitedPattern()}).
     * The compile warning of an invalid pattern is suppressed: the answer is this method's result.
     *
     * @param string $patternBody the pattern as the case writes it, without delimiters
     * @param string $patternModifiers e.g. `i`
     */
    public function isValidPattern(string $patternBody, string $patternModifiers = ''): bool
    {
        return @preg_match($this->buildDelimitedPattern($patternBody, $patternModifiers), '') !== false;
    }

    /**
     * A fixture's pattern body between `/` delimiters: every `/` the body does not escape itself is escaped, so a
     * pattern such as `https?://` matches instead of making the pattern invalid. A `\/` of the body stays as written.
     *
     * @param string $patternBody the pattern as the case writes it, without delimiters
     * @param string $patternModifiers e.g. `i`
     */
    public function buildDelimitedPattern(string $patternBody, string $patternModifiers = ''): string
    {
        $escapedPatternBody = '';
        $patternLength = strlen($patternBody);
        for ($characterPosition = 0; $characterPosition < $patternLength; $characterPosition++) {
            $character = $patternBody[$characterPosition];
            if ($character === '\\') {
                // an escape sequence is kept whole, so the character it escapes is never read as a delimiter
                $escapedPatternBody .= $character . ($patternBody[$characterPosition + 1] ?? '');
                $characterPosition++;
                continue;
            }
            $escapedPatternBody .= $character === '/' ? '\\/' : $character;
        }
        return '/' . $escapedPatternBody . '/' . $patternModifiers;
    }
}
