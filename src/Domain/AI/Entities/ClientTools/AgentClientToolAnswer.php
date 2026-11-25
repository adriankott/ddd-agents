<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What a waiting tool gets back from the user's client
 * ({@see \DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService::requestAndWait()}): the client's answer,
 * its refusal, or why no client answered.
 *
 * @phpstan-consistent-constructor
 */
class AgentClientToolAnswer extends ValueObject
{
    /** @var string The client did what was asked; `text` holds its answer */
    public const string KIND_ANSWERED = 'ANSWERED';

    /** @var string The client answered that it did not do it; `outcome` names why, `text` says it */
    public const string KIND_REFUSED = 'REFUSED';

    /** @var string No client of the user shows the conversation (no request was sent) */
    public const string KIND_NO_CLIENT = 'NO_CLIENT';

    /** @var string The client that shows the conversation is in the background and reads nothing (no request was sent) */
    public const string KIND_BACKGROUND = 'BACKGROUND';

    /** @var string A request was sent and the client did not answer in time */
    public const string KIND_NO_ANSWER = 'NO_ANSWER';

    /** @var string The request type is registered but switched off, or not registered (no request was sent) */
    public const string KIND_NOT_ENABLED = 'NOT_ENABLED';

    /** @var string One of the KIND_* constants */
    public string $kind;

    /** @var string|null The client's outcome code (`OK`, `UNSAVED_CHANGES`, …) */
    public ?string $outcome = null;

    /** @var string|null The client's answer text */
    public ?string $text = null;

    /** @var string|null Small facts of the answer (route, title, counts) as one flat JSON object */
    public ?string $detailsJson = null;

    /** @var int|null The id of the sent request; null when none was sent */
    public ?int $requestId = null;

    /** @var AgentScreenPicture|null The picture the client captured (a READ_SCREEN in the format picture), checked by the server */
    public ?AgentScreenPicture $picture = null;

    /** @var int How many seconds the tool waited */
    public int $secondsWaited = 0;

    public static function ofKind(string $kind): static
    {
        $agentClientToolAnswer = new static();
        $agentClientToolAnswer->kind = $kind;
        return $agentClientToolAnswer;
    }

    /** One fact of the answer: a string, an int, a bool, or null when the answer carries none */
    public function getDetailValue(string $detailName): string|int|bool|null
    {
        $details = json_decode((string)($this->detailsJson ?? '{}'), true);
        $detailValue = is_array($details) ? ($details[$detailName] ?? null) : null;
        return is_string($detailValue) || is_int($detailValue) || is_bool($detailValue) ? $detailValue : null;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->requestId ?? spl_object_id($this));
    }
}
