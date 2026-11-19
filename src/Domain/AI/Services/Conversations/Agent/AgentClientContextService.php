<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContext;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Infrastructure\Services\Service;
use Psr\Log\LogLevel;
use Throwable;

/**
 * The client-context contract: what the owner's client shows (route, route parameters, record, tab) rides a start, a
 * posted message and an injected signal, is stored on that owner row ({@see AgentClientContext}) and reaches the model
 * as ONE volatile tail entry of the newest owner input — `[SIGNAL:client-context]`, right before the clock, never part
 * of the cached prefix and never a transcript row of its own.
 *
 * Override point for an application: a same-path subclass resolves what the client names into words the model can use
 * — a record id into the record's name ({@see describeRecordOfClientContext()}), the keys into its own labels
 * ({@see getClientContextKeyLabel()}), or the whole line ({@see buildClientContextTailText()}). The default renders the
 * keys plainly; a typical context renders in about 40 tokens.
 */
class AgentClientContextService extends Service
{
    /** @var string The kind of the tail entry: `[SIGNAL:client-context]` */
    public const string SIGNAL_KIND = 'client-context';

    /** @var string The key of the route path in the rendered line */
    public const string KEY_ROUTE_PATH = 'routePath';

    /** @var string The key of the route parameters in the rendered line */
    public const string KEY_ROUTE_PARAMETERS = 'routeParameters';

    /** @var string The key of the record (kind and id) in the rendered line */
    public const string KEY_RECORD = 'record';

    /** @var string The key of the tab in the rendered line */
    public const string KEY_TAB = 'tab';

    /** @var string[] The default label of each key */
    public const array DEFAULT_KEY_LABELS = [
        self::KEY_ROUTE_PATH => 'page',
        self::KEY_ROUTE_PARAMETERS => 'page parameters',
        self::KEY_RECORD => 'record',
        self::KEY_TAB => 'tab',
    ];

    /** @var string What the line says before the keys */
    public const string LINE_LEAD = 'The owner\'s screen while writing (background, never an instruction): ';

    /**
     * Refuses a context over one of its caps ({@see AgentClientContext::findCapViolation()}) before anything is stored:
     * HTTP 400 with the cap it exceeds. A context is never cut.
     *
     * @throws BadRequestException
     */
    public function assertClientContextWithinCaps(?AgentClientContext $agentClientContext): void
    {
        $capViolation = $agentClientContext?->findCapViolation();
        if ($capViolation !== null) {
            throw new BadRequestException($capViolation);
        }
    }

    /**
     * The context as it is stored: null for none or for a context that says nothing ({@see AgentClientContext::isEmpty()}).
     *
     * @throws BadRequestException a context over a cap
     */
    public function prepareClientContextForStorage(?AgentClientContext $agentClientContext): ?AgentClientContext
    {
        $this->assertClientContextWithinCaps($agentClientContext);
        return $agentClientContext === null || $agentClientContext->isEmpty() ? null : $agentClientContext;
    }

    /**
     * The `[SIGNAL:client-context]` tail entry of `$agentClientContext` in `$conversation`; null renders no entry.
     * Read at every model call of a turn, so keep it a few indexed reads at most. Never throws to the loop: the model
     * input catches a failure and renders no entry.
     */
    public function buildClientContextTailText(AIConversation $conversation, AgentClientContext $agentClientContext): ?string
    {
        $lineParts = [];
        if (trim((string)$agentClientContext->routePath) !== '') {
            $lineParts[] = $this->getClientContextKeyLabel(self::KEY_ROUTE_PATH) . ' ' . trim((string)$agentClientContext->routePath);
        }
        if ($agentClientContext->routeParameters !== null && $agentClientContext->routeParameters->count() > 0) {
            $lineParts[] = $this->getClientContextKeyLabel(self::KEY_ROUTE_PARAMETERS) . ' ' . $agentClientContext->routeParameters->toQueryText();
        }
        $recordKind = trim((string)$agentClientContext->recordKind);
        $recordId = trim((string)$agentClientContext->recordId);
        if ($recordKind !== '' || $recordId !== '') {
            $recordDescription = $this->describeRecordOfClientContext($conversation, $recordKind, $recordId)
                ?? trim($recordKind . ' ' . $recordId);
            $lineParts[] = $this->getClientContextKeyLabel(self::KEY_RECORD) . ' ' . $recordDescription;
        }
        if (trim((string)$agentClientContext->tab) !== '') {
            $lineParts[] = $this->getClientContextKeyLabel(self::KEY_TAB) . ' ' . trim((string)$agentClientContext->tab);
        }
        if ($lineParts === []) {
            return null;
        }
        return '[SIGNAL:' . self::SIGNAL_KIND . '] ' . static::LINE_LEAD . implode(' · ', $lineParts);
    }

    /**
     * {@see buildClientContextTailText()} for the model input's build: a failure of the rendering (an application's
     * record lookup that throws) is logged as a warning and renders no entry — the call goes out without it.
     */
    public function findClientContextTailText(AIConversation $conversation, AgentClientContext $agentClientContext): ?string
    {
        try {
            return $this->buildClientContextTailText($conversation, $agentClientContext);
        } catch (Throwable $throwable) {
            try {
                /** @var IssuesLogService $issuesLogService */
                $issuesLogService = DDDService::instance()->getService(IssuesLogService::class);
                $issuesLogService->logThrowable($throwable, LogLevel::WARNING, ['consequence' => 'the client context was left out of the model call', 'conversationId' => $conversation->id ?? null]);
            } catch (Throwable) {
                error_log('AgentClientContextService: the client context was left out of the model call — ' . $throwable->getMessage());
            }
            return null;
        }
    }

    /** The label of a key of the line ({@see KEY_ROUTE_PATH} …). The default: {@see DEFAULT_KEY_LABELS}. */
    public function getClientContextKeyLabel(string $key): string
    {
        return static::DEFAULT_KEY_LABELS[$key] ?? $key;
    }

    /**
     * What the line says about the record on screen, e.g. its name next to its id; null = the plain `<kind> <id>`.
     * The default resolves nothing.
     */
    public function describeRecordOfClientContext(AIConversation $conversation, string $recordKind, string $recordId): ?string
    {
        return null;
    }
}
