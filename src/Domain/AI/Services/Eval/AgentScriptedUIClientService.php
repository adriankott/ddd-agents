<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Repo\DB\Conversations\DBAIConversationModel;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\AI\Services\Conversations\Agent\AgentEvalAccountsService;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The simulated client of the `Agent.UI` evaluation runs: it plays the user's client for the conversations of the
 * evaluation accounts ({@see AgentEvalAccountsService}), from fixture screens in `config/app/AI/Evals/Agent/Screens/`
 * of any config root (the application's first).
 *
 * - A case sets the screen with {@see setScreen()} (the application's harness tool, in `restore.setup`): the screen
 *   is kept per evaluation account (a setup call has no conversation yet). Screen cases are therefore `exclusive`.
 * - While a screen is set, a client tool request of a conversation of the evaluation account is answered here the
 *   moment it is stored, as the real client would answer it ({@see answerRequest()}), and noted in a log.
 * - `expect.stateAfterRun` reads the log through {@see readLog()} (`opened orders tab items`, `marked f.endDate`).
 *
 * Refused in the `prod` environment ({@see isScriptedClientAllowed()}). A fixture is one JSON object: `markdown` (the
 * screen as `getCurrentScreen` reads it), `route`, `title`, `unsavedChanges`, `dialogOpen`, `references` (reference =>
 * label), `screensByTarget` (`<target>[.<tab>]` => fixture name); the fixture `catalog` holds `lines` (the
 * `listScreens` lines) and `targetKeys`.
 */
class AgentScriptedUIClientService extends Service
{
    /** @var string The client id the scripted client answers as */
    public const string SCRIPTED_CLIENT_ID = 'scripted-ui-client';

    /** @var string The fixture folder below a config root */
    public const string FIXTURE_FOLDER = 'AI/Evals/Agent/Screens';

    /** @var string The catalog fixture (the listScreens answer) */
    public const string CATALOG_FIXTURE = 'catalog';

    /** @var int How long a set screen and its log are kept, in seconds */
    public const int STATE_TTL_SECONDS = 1800;

    /** @var string A fixture name */
    public const string FIXTURE_NAME_PATTERN = '/^[a-z0-9][a-z0-9\-]{0,63}$/';

    /** @var string Prefix of the cache key of an account's scripted state */
    public const string CACHE_KEY_PREFIX = 'agent-scripted-ui-';

    /** @var string The screen that means: no client attached */
    public const string NO_CLIENT_FIXTURE = 'none';

    /** @var string The environment in which no scripted client runs */
    public const string REFUSED_ENVIRONMENT = 'prod';

    /**
     * Sets the screen the scripted client shows for the evaluation account and clears its log. `none` = no client is
     * attached (the tools answer "no client attached"); it keeps the log for the case's state check.
     *
     * @return string The line for the harness's report
     * @throws BadRequestException
     * @throws ForbiddenException
     */
    public function setScreen(int $accountId, string $fixtureName): string
    {
        $this->requireScriptedClientForAccount($accountId);
        if ($fixtureName !== static::NO_CLIENT_FIXTURE) {
            $this->loadFixture($fixtureName);
        }
        $logLines = $fixtureName === static::NO_CLIENT_FIXTURE ? ($this->readState($accountId)['log'] ?? []) : [];
        Cache::instance()->set($this->buildCacheKey($accountId), ['screen' => $fixtureName, 'log' => $logLines], static::STATE_TTL_SECONDS);
        return $fixtureName === static::NO_CLIENT_FIXTURE ? 'scripted screen: no client attached' : 'scripted screen: ' . $fixtureName;
    }

    /**
     * @return string The log of the scripted client, one entry per request, oldest first
     * @throws ForbiddenException
     */
    public function readLog(int $accountId): string
    {
        $this->requireScriptedClientForAccount($accountId);
        $state = $this->readState($accountId);
        if ($state === null) {
            return 'no scripted screen set';
        }
        $logLines = $state['log'] ?? [];
        return 'scripted screen ' . ($state['screen'] ?? '') . '; requests: ' . ($logLines === [] ? 'none' : implode('; ', $logLines));
    }

    /**
     * Whether the scripted client plays the client of a conversation: a conversation of an evaluation account while a
     * screen other than `none` is set. Asked for every request; false outside the allowed environment.
     */
    public function hasScriptedScreen(int $conversationId): bool
    {
        $accountId = $this->findEvalAccountIdOfConversation($conversationId);
        if ($accountId === null) {
            return false;
        }
        $state = $this->readState($accountId);
        return $state !== null && ($state['screen'] ?? static::NO_CLIENT_FIXTURE) !== static::NO_CLIENT_FIXTURE;
    }

    /**
     * Answers a stored request as the real client would, and notes it in the log.
     *
     * @throws BadRequestException
     */
    public function answerRequest(AgentClientToolRequest $request): void
    {
        $accountId = (int)$request->accountId;
        $state = $this->readState($accountId) ?? ['screen' => static::NO_CLIENT_FIXTURE, 'log' => []];
        $screen = $this->loadFixture((string)$state['screen']);
        [$outcome, $text, $details, $logLine, $nextScreen] = match ($request->requestType) {
            AgentClientToolRequest::TYPE_READ_SCREEN => $this->answerReadScreen($screen),
            AgentClientToolRequest::TYPE_LIST_TARGETS => $this->answerListTargets($request),
            AgentClientToolRequest::TYPE_OPEN_TARGET => $this->answerOpenTarget($screen, $request),
            AgentClientToolRequest::TYPE_HIGHLIGHT => $this->answerHighlight($screen, $request),
            default => ['UNKNOWN_REQUEST', 'This request type is not known.', [], 'unknown request', null],
        };
        $state['log'][] = $logLine;
        if ($nextScreen !== null) {
            $state['screen'] = $nextScreen;
        }
        Cache::instance()->set($this->buildCacheKey($accountId), $state, static::STATE_TTL_SECONDS);
        $this->getAgentClientToolRequestsService()->answerRequest(
            (int)$request->conversationId,
            (int)$request->id,
            static::SCRIPTED_CLIENT_ID,
            $outcome,
            $text,
            (string)json_encode((object)$details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    /** Whether a scripted client may run in this process: never in the `prod` environment */
    public function isScriptedClientAllowed(): bool
    {
        return (string)(Config::getEnv('APP_ENV') ?? '') !== static::REFUSED_ENVIRONMENT;
    }

    /**
     * @param mixed[] $screen
     * @return mixed[] outcome, text, details, log line, next screen
     */
    protected function answerReadScreen(array $screen): array
    {
        return ['OK', (string)($screen['markdown'] ?? ''), $this->buildDetails($screen), 'read ' . $screen['name'], null];
    }

    /**
     * @return mixed[] outcome, text, details, log line, next screen
     * @throws BadRequestException
     */
    protected function answerListTargets(AgentClientToolRequest $request): array
    {
        $catalog = $this->loadFixture(static::CATALOG_FIXTURE);
        $searchWords = preg_split('/\s+/', mb_strtolower(trim((string)$request->getArgument('searchText'))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        foreach ((array)($catalog['lines'] ?? []) as $line) {
            $lowerLine = mb_strtolower((string)$line);
            $matchesAll = true;
            foreach ($searchWords as $searchWord) {
                if (!str_contains($lowerLine, $searchWord)) {
                    $matchesAll = false;
                    break;
                }
            }
            if ($matchesAll) {
                $lines[] = (string)$line;
            }
        }
        $text = $lines === []
            ? 'No screen matches. Call listScreens without searchText for all of them.'
            : 'Screens this user can open (' . count($lines) . '). Pass the key to openScreen.' . "\n" . implode("\n", $lines);
        return ['OK', $text, ['targets' => count($lines)], 'listed ' . ($searchWords === [] ? 'all' : implode(' ', $searchWords)), null];
    }

    /**
     * @param mixed[] $screen
     * @return mixed[] outcome, text, details, log line, next screen
     * @throws BadRequestException
     */
    protected function answerOpenTarget(array $screen, AgentClientToolRequest $request): array
    {
        $target = (string)$request->getArgument('target');
        $tab = $request->getArgument('tab');
        $tab = is_string($tab) ? $tab : null;
        $recordId = $request->getArgument('recordId');
        $recordId = is_int($recordId) ? $recordId : null;
        $logLine = 'opened ' . $target . ($recordId !== null ? ' record ' . $recordId : '') . ($tab !== null ? ' tab ' . $tab : '');
        if (($screen['unsavedChanges'] ?? false) === true) {
            return ['UNSAVED_CHANGES', 'The open form has unsaved changes.', $this->buildDetails($screen), 'refused ' . $logLine . ': unsaved changes', null];
        }
        if (($screen['dialogOpen'] ?? false) === true) {
            return ['DIALOG_OPEN', 'A dialog is open on the screen.', $this->buildDetails($screen), 'refused ' . $logLine . ': dialog open', null];
        }
        $catalog = $this->loadFixture(static::CATALOG_FIXTURE);
        $targetKeys = array_map('strval', (array)($catalog['targetKeys'] ?? []));
        if (!in_array($target, $targetKeys, true)) {
            return ['UNKNOWN_TARGET', 'There is no screen with the key ' . $target . '.', [], 'refused ' . $logLine . ': unknown target', null];
        }
        $screensByTarget = (array)($screen['screensByTarget'] ?? []);
        $nextScreenKey = $target . ($tab !== null ? '.' . $tab : '');
        $nextScreenName = isset($screensByTarget[$nextScreenKey]) ? (string)$screensByTarget[$nextScreenKey] : null;
        if ($nextScreenName === null) {
            $route = '/' . str_replace('.', '/', $target) . ($recordId !== null ? '/' . $recordId : '') . ($tab !== null ? '/' . $tab : '');
            return ['OK', '', ['route' => $route, 'title' => $target], $logLine, null];
        }
        $nextScreen = $this->loadFixture($nextScreenName);
        $text = $request->getArgument('includeScreen') === true ? (string)($nextScreen['markdown'] ?? '') : '';
        return ['OK', $text, $this->buildDetails($nextScreen), $logLine, $nextScreenName];
    }

    /**
     * @param mixed[] $screen
     * @return mixed[] outcome, text, details, log line, next screen
     */
    protected function answerHighlight(array $screen, AgentClientToolRequest $request): array
    {
        $references = $request->getArgument('references');
        $references = is_array($references) ? $references : [];
        $labels = (array)($screen['references'] ?? []);
        $markedLines = [];
        $missingReferences = [];
        foreach ($references as $reference) {
            if (isset($labels[$reference])) {
                $markedLines[] = $reference . ' "' . $labels[$reference] . '"';
            } else {
                $missingReferences[] = $reference;
            }
        }
        // the lines of the package's client runner (web: ClientToolRequestRunner)
        $lines = [];
        foreach ($markedLines as $markedLine) {
            $lines[] = 'Marked ' . $markedLine . '.';
        }
        if ($missingReferences !== []) {
            $lines[] = 'Not on the screen: ' . implode(', ', $missingReferences) . '.';
        }
        $outcome = $markedLines === [] ? 'NOT_ON_SCREEN' : 'OK';
        return [
            $outcome,
            implode("\n", $lines),
            ['marked' => count($markedLines), 'missing' => count($missingReferences)],
            'marked ' . ($markedLines === [] ? 'nothing' : implode(' ', array_slice($references, 0, 5))),
            null,
        ];
    }

    /**
     * @param mixed[] $screen
     * @return mixed[] route, title, unsavedChanges, visible
     */
    protected function buildDetails(array $screen): array
    {
        return [
            'route' => (string)($screen['route'] ?? ''),
            'title' => (string)($screen['title'] ?? ''),
            'unsavedChanges' => ($screen['unsavedChanges'] ?? false) === true,
            'visible' => true,
        ];
    }

    /**
     * The fixture `$fixtureName` of the first config root that holds it.
     *
     * @return mixed[]
     * @throws BadRequestException
     */
    protected function loadFixture(string $fixtureName): array
    {
        if (preg_match(static::FIXTURE_NAME_PATTERN, $fixtureName) !== 1) {
            throw new BadRequestException('A screen fixture is named by lower-case letters, digits and dashes.');
        }
        foreach (Config::getConfigRootDirectories() as $configRootDirectory) {
            $fixturePath = rtrim((string)$configRootDirectory, '/') . '/' . static::FIXTURE_FOLDER . '/' . $fixtureName . '.json';
            if (!is_file($fixturePath)) {
                continue;
            }
            $fixture = json_decode((string)file_get_contents($fixturePath), true);
            if (!is_array($fixture)) {
                throw new BadRequestException('The screen fixture ' . $fixtureName . ' is no JSON object.');
            }
            $fixture['name'] = $fixtureName;
            return $fixture;
        }
        throw new BadRequestException('No screen fixture ' . $fixtureName . ' in ' . static::FIXTURE_FOLDER . '.');
    }

    /** @return mixed[]|null the screen and the log of the account, or null when none is set */
    protected function readState(int $accountId): ?array
    {
        $state = Cache::instance()->get($this->buildCacheKey($accountId));
        return is_array($state) ? $state : null;
    }

    /** The evaluation account that owns the conversation and has a screen set; null for every other conversation */
    protected function findEvalAccountIdOfConversation(int $conversationId): ?int
    {
        if (!$this->isScriptedClientAllowed()) {
            return null;
        }
        foreach ($this->getAgentEvalAccountsService()->getEvalAccountIds() as $evalAccountId) {
            if ($this->readState((int)$evalAccountId) !== null && $this->isConversationOfAccount($conversationId, (int)$evalAccountId)) {
                return (int)$evalAccountId;
            }
        }
        return null;
    }

    protected function isConversationOfAccount(int $conversationId, int $accountId): bool
    {
        $rows = EntityManagerFactory::getInstance()->createQueryBuilder()
            ->select('scriptedConversation.accountId')
            ->from(DBAIConversationModel::class, 'scriptedConversation')
            ->where('scriptedConversation.id = :scriptedConversationId')
            ->setParameter('scriptedConversationId', $conversationId)
            ->getQuery()
            ->getArrayResult();
        return (int)($rows[0]['accountId'] ?? 0) === $accountId;
    }

    /** @throws ForbiddenException outside the allowed environment or for an account that is no evaluation account */
    protected function requireScriptedClientForAccount(int $accountId): void
    {
        if (!$this->isScriptedClientAllowed()) {
            throw new ForbiddenException('The scripted client of the evaluation runs does not run in this environment.');
        }
        if (!in_array($accountId, array_map('intval', $this->getAgentEvalAccountsService()->getEvalAccountIds()), true)) {
            throw new ForbiddenException('The scripted client plays only the client of an evaluation account.');
        }
    }

    protected function buildCacheKey(int $accountId): string
    {
        return static::CACHE_KEY_PREFIX . $accountId;
    }

    protected function getAgentEvalAccountsService(): AgentEvalAccountsService
    {
        /** @var AgentEvalAccountsService $agentEvalAccountsService */
        $agentEvalAccountsService = DDDService::instance()->getService(AgentEvalAccountsService::class);
        return $agentEvalAccountsService;
    }

    protected function getAgentClientToolRequestsService(): AgentClientToolRequestsService
    {
        /** @var AgentClientToolRequestsService $agentClientToolRequestsService */
        $agentClientToolRequestsService = DDDService::instance()->getService(AgentClientToolRequestsService::class);
        return $agentClientToolRequestsService;
    }
}
