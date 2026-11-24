<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\ClientTools;

use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContextRouteParameters;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * What the user's client reports about its UI with every message and every run-state read (`Agent.UI`): the route
 * with its parameters, the tab and view, the references of unsaved and of invalid fields, an open dialog, whether the
 * client is visible and when it captured the state. Tokens of one pattern only, capped in number and length: the
 * server keeps no free text of the client ({@see normalize()}); the state note the model reads is written by the
 * server from these tokens and the application's screen catalog
 * ({@see \DDD\Domain\AI\Services\ClientTools\AgentUIScreenCatalogService}).
 *
 * @phpstan-consistent-constructor
 */
class AgentUIState extends ValueObject
{
    /** @var string A reference, tab, view or dialog key: letters, digits, `.`, `-`, `_`, at most 100 characters */
    public const string TOKEN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,99}$/';

    /** @var string A route path: `/` and URL path characters, at most 512 characters */
    public const string ROUTE_PATH_PATTERN = '#^/[A-Za-z0-9/_\-.~%:@]{0,511}$#';

    /** @var int The most references of unsaved or invalid fields that are kept */
    public const int MAX_REFERENCES = 20;

    /** @var int The most route parameters that are kept */
    public const int MAX_ROUTE_PARAMETERS = 20;

    /** @var int The longest route parameter value that is kept */
    public const int MAX_ROUTE_PARAMETER_VALUE_CHARS = 100;

    /** @var string|null The path of the route the client shows, e.g. `/orders/42` */
    public ?string $routePath = null;

    /** @var AgentClientContextRouteParameters|null The parameters of that route, by name */
    public ?AgentClientContextRouteParameters $routeParameters = null;

    /** @var string|null The tab of the screen, e.g. `details` */
    public ?string $tab = null;

    /** @var string|null The view of the screen (list, board, …) */
    public ?string $view = null;

    /** @var string[] The references of the fields with unsaved changes */
    public array $unsavedFieldReferences = [];

    /** @var string[] The references of the fields with a validation message */
    public array $invalidFieldReferences = [];

    /** @var string|null The key of a dialog or drawer open on the screen */
    public ?string $openDialogKey = null;

    /** @var bool|null Whether the client is visible (a foreground tab) */
    public ?bool $visible = null;

    /** @var DateTime|null When the client captured the state */
    public ?DateTime $capturedAt = null;

    /** Whether `$token` is a reference, tab, view or dialog key */
    public static function isValidToken(?string $token): bool
    {
        return $token !== null && preg_match(static::TOKEN_PATTERN, $token) === 1;
    }

    /** Whether `$routePath` is a route path the server keeps */
    public static function isValidRoutePath(?string $routePath): bool
    {
        return $routePath !== null && preg_match(static::ROUTE_PATH_PATTERN, $routePath) === 1;
    }

    /**
     * A copy with only what the server keeps: a route path of the pattern, at most {@see MAX_ROUTE_PARAMETERS}
     * parameters with token names and values of at most {@see MAX_ROUTE_PARAMETER_VALUE_CHARS} characters without
     * control characters, tokens of the pattern, at most {@see MAX_REFERENCES} references each. Everything else is
     * dropped, never refused: the client's report never fails a message.
     */
    public function normalize(): static
    {
        $normalizedUIState = new static();
        $normalizedUIState->routePath = static::isValidRoutePath($this->routePath) ? $this->routePath : null;
        if ($this->routeParameters !== null && $normalizedUIState->routePath !== null) {
            $routeParameters = new AgentClientContextRouteParameters();
            foreach ($this->routeParameters->getElements() as $routeParameter) {
                if ($routeParameters->count() >= static::MAX_ROUTE_PARAMETERS) {
                    break;
                }
                $isKept = static::isValidToken($routeParameter->name)
                    && mb_strlen($routeParameter->value) <= static::MAX_ROUTE_PARAMETER_VALUE_CHARS
                    && preg_match('/[\x00-\x1F\x7F]/u', $routeParameter->value) !== 1;
                if ($isKept) {
                    $routeParameters->add($routeParameter);
                }
            }
            $normalizedUIState->routeParameters = $routeParameters->count() > 0 ? $routeParameters : null;
        }
        $normalizedUIState->tab = static::isValidToken($this->tab) ? $this->tab : null;
        $normalizedUIState->view = static::isValidToken($this->view) ? $this->view : null;
        $normalizedUIState->unsavedFieldReferences = static::keepValidReferences($this->unsavedFieldReferences);
        $normalizedUIState->invalidFieldReferences = static::keepValidReferences($this->invalidFieldReferences);
        $normalizedUIState->openDialogKey = static::isValidToken($this->openDialogKey) ? $this->openDialogKey : null;
        $normalizedUIState->visible = $this->visible;
        $normalizedUIState->capturedAt = $this->capturedAt;
        return $normalizedUIState;
    }

    /**
     * @param mixed[] $references
     * @return string[]
     */
    protected static function keepValidReferences(array $references): array
    {
        $validReferences = [];
        foreach ($references as $reference) {
            if (is_string($reference) && static::isValidToken($reference) && !in_array($reference, $validReferences, true)) {
                $validReferences[] = $reference;
            }
            if (count($validReferences) >= static::MAX_REFERENCES) {
                break;
            }
        }
        return $validReferences;
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic(md5((string)json_encode([$this->routePath, $this->tab, $this->view])));
    }
}
