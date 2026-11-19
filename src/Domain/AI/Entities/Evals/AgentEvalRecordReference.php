<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * A record a fixture names instead of hard-coding its id: `{{ref:<kind>:<name>}}` in any string of a case (prompt,
 * follow-ups, `expect`, `restore`, `stateAfterRun`), e.g. `{{ref:entry:Spring opening}}`. Before the case runs, the
 * runner asks the application for the record's value in the run's scope
 * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::resolveRecordReference()}) and puts it in place: a
 * string that is nothing but the token takes the value as resolved (an id stays a number), a token inside a longer
 * text takes its text. A reference the application cannot resolve ends the case before the agent runs
 * (not applicable, like a failed preparation step).
 *
 * The kind and the name are the application's: the module only finds the tokens. A kind is letters, digits, `_`, `-`
 * and `.`; a name is any text without `}` (trimmed).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalRecordReference extends ValueObject
{
    /** @var string The pattern of a token; group 1 = the kind, group 2 = the name */
    public const string TOKEN_PATTERN = '/\{\{ref:([A-Za-z0-9_.\-]+):([^}]+)\}\}/';

    /** @var string The token as written in the fixture */
    public string $token = '';

    /** @var string The kind of the record (the application's word, e.g. `entry`) */
    public string $recordKind = '';

    /** @var string The name of the record in its kind */
    public string $recordName = '';

    /** @var string|int|null The value the application resolved the record to; null = not resolved (yet) */
    public string|int|null $resolvedValue = null;

    /** The reference of one token match (`[token, kind, name]`). */
    public static function fromTokenMatch(string $token, string $recordKind, string $recordName): static
    {
        $agentEvalRecordReference = static::newInstance();
        $agentEvalRecordReference->token = $token;
        $agentEvalRecordReference->recordKind = $recordKind;
        $agentEvalRecordReference->recordName = trim($recordName);
        return $agentEvalRecordReference;
    }

    /** Whether `$text` is this one token and nothing else (surrounding whitespace aside). */
    public function isWholeText(string $text): bool
    {
        return trim($text) === $this->token;
    }

    /** The key of the record in its kind: one resolution per kind and name. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->recordKind . ':' . $this->recordName);
    }
}
