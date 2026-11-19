<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the owner's client shows while the owner writes: the route, its parameters, the record on screen and the tab.
 * Sent by the client with a start, a posted message and an injected signal, stored on that owner row
 * ({@see AIConversationMessage::$clientContext}) and rendered for the model as ONE volatile tail entry
 * `[SIGNAL:client-context]` of the newest owner input
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\AgentClientContextService::buildClientContextTailText()}): never
 * part of the cached prefix, never a transcript row of its own.
 *
 * Every field is optional and capped; an input over a cap is refused (HTTP 400), never cut
 * ({@see findCapViolation()}). Control characters are refused too: the context renders as one line.
 *
 * @phpstan-consistent-constructor
 */
class AgentClientContext extends ValueObject
{
    /** @var int The longest route path, in characters */
    public const int MAX_ROUTE_PATH_CHARS = 512;

    /** @var int The longest route parameters, in characters of their `name=value&name=value` text */
    public const int MAX_ROUTE_PARAMETERS_CHARS = 512;

    /** @var int The most route parameters */
    public const int MAX_ROUTE_PARAMETER_COUNT = 20;

    /** @var int The longest record kind, in characters */
    public const int MAX_RECORD_KIND_CHARS = 64;

    /** @var int The longest record id, in characters */
    public const int MAX_RECORD_ID_CHARS = 128;

    /** @var int The longest tab name, in characters */
    public const int MAX_TAB_CHARS = 64;

    /** @var string|null The path of the route the client shows, e.g. `/tickets/42` */
    public ?string $routePath = null;

    /** @var AgentClientContextRouteParameters|null The parameters of that route, by name */
    public ?AgentClientContextRouteParameters $routeParameters = null;

    /** @var string|null The kind of the record on screen, as the application names it, e.g. `ticket` */
    public ?string $recordKind = null;

    /** @var string|null The id of the record on screen, as text */
    public ?string $recordId = null;

    /** @var string|null The tab the client shows, e.g. `details` */
    public ?string $tab = null;

    /** Whether the context says nothing: every field empty. */
    public function isEmpty(): bool
    {
        return trim((string)$this->routePath) === ''
            && ($this->routeParameters === null || $this->routeParameters->count() === 0)
            && trim((string)$this->recordKind) === ''
            && trim((string)$this->recordId) === ''
            && trim((string)$this->tab) === '';
    }

    /**
     * The first cap the context exceeds, as the sentence a refusal carries; null when it keeps every cap.
     */
    public function findCapViolation(): ?string
    {
        $textFieldCaps = [
            'routePath' => [$this->routePath, static::MAX_ROUTE_PATH_CHARS],
            'recordKind' => [$this->recordKind, static::MAX_RECORD_KIND_CHARS],
            'recordId' => [$this->recordId, static::MAX_RECORD_ID_CHARS],
            'tab' => [$this->tab, static::MAX_TAB_CHARS],
        ];
        foreach ($textFieldCaps as $fieldName => [$fieldValue, $maxChars]) {
            if ($fieldValue === null) {
                continue;
            }
            if (mb_strlen($fieldValue) > $maxChars) {
                return "clientContext.{$fieldName} is longer than {$maxChars} characters.";
            }
            if (static::containsControlCharacters($fieldValue)) {
                return "clientContext.{$fieldName} contains control characters.";
            }
        }
        if ($this->routeParameters === null) {
            return null;
        }
        if ($this->routeParameters->count() > static::MAX_ROUTE_PARAMETER_COUNT) {
            return 'clientContext.routeParameters holds more than ' . static::MAX_ROUTE_PARAMETER_COUNT . ' parameters.';
        }
        foreach ($this->routeParameters->getElements() as $agentClientContextRouteParameter) {
            if (trim($agentClientContextRouteParameter->name) === '') {
                return 'clientContext.routeParameters holds a parameter without a name.';
            }
            if (static::containsControlCharacters($agentClientContextRouteParameter->toAssignmentText())) {
                return 'clientContext.routeParameters contains control characters.';
            }
        }
        if (mb_strlen($this->routeParameters->toQueryText()) > static::MAX_ROUTE_PARAMETERS_CHARS) {
            return 'clientContext.routeParameters is longer than ' . static::MAX_ROUTE_PARAMETERS_CHARS . ' characters.';
        }
        return null;
    }

    protected static function containsControlCharacters(string $text): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/u', $text) === 1;
    }
}
