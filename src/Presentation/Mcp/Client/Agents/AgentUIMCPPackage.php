<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Infrastructure\Services\AuthService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentUIToolResponseDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\GetCurrentScreenRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\HighlightOnScreenRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\ListScreensRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\OpenScreenRequestDto;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsTrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;

/**
 * The UI tools of `Agent.UI`: read the user's open screen, list and open screens, point at elements. Every tool sends
 * one request to the user's client and waits a few seconds for its answer ({@see AgentClientToolRequestsService});
 * without a client it answers at once. The client executes the requests with its adapters (the npm package's
 * `ClientToolRequestRunner`); what a screen is and how to open it is the application's.
 *
 * Effect READ for all of them (no stored data changes; navigating the user's own client and drawing a mark are not
 * data writes), never reused within a turn (the screen changes), never parallel (two requests to one client never run
 * in parallel child processes). The client's text is wrapped as untrusted. All result texts are constants here. Gated
 * by {@see AgentAccountRights::USE_UI}; an application that keeps the tools to its staff adds its gate in a same-path
 * subclass (e.g. `#[MCPRequiresAdminWithAccessToAllResources]`).
 */
#[MCPRequiresAccountRights(AgentAccountRights::USE_UI)]
#[MCPToolExecutionTimeout(25, 'waits for the user\'s client (at most 15 s)')]
class AgentUIMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.ui';

    public static ?string $description = 'Read the user\'s open screen, list and open screens, point at fields and buttons.';

    /** @var int How long getCurrentScreen waits for the client */
    public const int READ_WAIT_SECONDS = 12;

    /** @var int How long listScreens waits */
    public const int LIST_WAIT_SECONDS = 8;

    /** @var int How long openScreen waits (navigation plus up to 6 s for the page to settle) */
    public const int OPEN_WAIT_SECONDS = 15;

    /** @var int How long highlightOnScreen waits */
    public const int HIGHLIGHT_WAIT_SECONDS = 8;

    /** @var int The most references one highlight takes */
    public const int MAX_REFERENCES = 5;

    /** @var int The longest note of a highlight */
    public const int MAX_NOTE_CHARS = 120;

    /** @var int The longest reference, target, tab or filter */
    public const int MAX_TOKEN_CHARS = 100;

    /** @var int The most filters of one openScreen */
    public const int MAX_FILTERS = 6;

    /** @var string A reference, target key, tab or filter key: letters, digits, `.`, `-`, `_` */
    public const string TOKEN_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._\-]{0,99}$/';

    public const string NO_CLIENT = 'No client is attached to this conversation (the agent window is closed or nobody is at the screen). Nothing was read or changed. Answer from the data tools, or ask the user to open the agent window.';

    public const string BACKGROUND_CLIENT = 'The user\'s agent window is in the background; nothing was read or changed. Answer from the data tools, or ask the user to come back to it.';

    public const string NO_ANSWER = 'The user\'s client did not answer within %d s. Nothing was read.';

    public const string NO_ANSWER_OPEN = 'The user\'s client did not answer within %d s. The navigation may still happen; the next ui note tells where the user is.';

    public const string SCREEN_FRAME = 'The user\'s screen as the client shows it now%s. Values can be unsaved: read a value with the data tool before you write it. References work with highlightOnScreen.';

    public const string CLIENT_IN_BACKGROUND = ' (client in the background)';

    public const string OPENED = 'Opened %s (%s).';

    public const string NOT_OPENED = 'Not opened: %s';

    public const string NOT_DONE = 'Not done: %s';

    public const string MISSING_TARGET = 'Not opened: target is missing. Take a key from listScreens.';

    public const string INVALID_ARGUMENT = 'Nothing sent to the client: %s is not valid (letters, digits, ".", "-", "_", at most 100 characters).';

    public const string MISSING_REFERENCES = 'Nothing marked: pass 1 to 5 references of the newest getCurrentScreen, or anchors (a.…).';

    /** @var string[] The sentence and next move per refusal code of the client */
    public const array REFUSALS = [
        'UNSAVED_CHANGES' => 'the open form has unsaved changes. Tell the user to save or discard them; do not try again.',
        'DIALOG_OPEN' => 'a dialog or drawer is open on the screen. Ask the user to close it.',
        'UNSAVED_STATE_UNKNOWN' => 'this screen cannot tell whether it has unsaved changes. Ask the user to leave it, or to say it is safe.',
        'UNKNOWN_TARGET' => 'there is no screen with this key. Take a key from listScreens.',
        'MISSING_PARAMETER' => 'the screen needs a record id. Pass recordId.',
        'UNKNOWN_TAB' => 'the screen has no such tab. listScreens names its tabs.',
        'UNKNOWN_FILTER' => 'the screen has no such filter. listScreens names its filters.',
        'NOT_ON_SCREEN' => 'none of the references is on the screen. Read the screen again with getCurrentScreen and take its references.',
        'EXPIRED_BEFORE_START' => 'the client received the request too late. Nothing was done.',
        'READ_FAILED' => 'the client could not read the screen. Answer from the data tools.',
    ];

    public const string REFUSED_OTHER = 'the client refused (%s).';

    /**
     * What the user sees right now: URL, title, visible table rows, form fields with unsaved values, tab, filters,
     * selection, validation messages, and short references for highlightOnScreen. For stored data use the data tools;
     * the ui note already names the open screen and record. Never write a value from this result without reading it
     * with the data tool first.
     *
     * @example {}
     * @example {"focus": "f.description"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolSummary('Reads what the user sees on the open screen (unsaved values, visible rows, messages) as Markdown with references.')]
    #[MCPToolReturnsUntrustedContent('a screen shows texts other people wrote')]
    public function getCurrentScreen(
        GetCurrentScreenRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $arguments = [];
        $focus = trim((string)$requestDto->focus);
        if ($focus !== '') {
            if (preg_match(static::TOKEN_PATTERN, $focus) !== 1) {
                return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'focus'), 'INVALID_ARGUMENT');
            }
            $arguments['focus'] = $focus;
        }
        $answer = $this->send($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_READ_SCREEN, $arguments, static::READ_WAIT_SECONDS);
        if ($answer->kind !== AgentClientToolAnswer::KIND_ANSWERED) {
            return static::buildUnansweredResponse($answer, static::READ_WAIT_SECONDS, false);
        }
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->outcome = $answer->outcome;
        $responseDto->lines[] = sprintf(static::SCREEN_FRAME, $answer->getDetailValue('visible') === false ? static::CLIENT_IN_BACKGROUND : '');
        $responseDto->screenText = $answer->text;
        return $responseDto;
    }

    /**
     * The screens this user can open: menu entries and record screens with their tabs and filters. Pass a key to
     * openScreen.
     *
     * @example {"searchText": "crons"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolSummary('Lists the screens this user can open (keys, tabs, filters) for openScreen; searchText narrows.')]
    #[MCPToolReturnsTrustedContent('the application\'s own screen catalogue')]
    public function listScreens(
        ListScreensRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $arguments = [];
        $searchText = trim((string)$requestDto->searchText);
        if ($searchText !== '') {
            $arguments['searchText'] = mb_substr($searchText, 0, static::MAX_TOKEN_CHARS);
        }
        $answer = $this->send($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_LIST_TARGETS, $arguments, static::LIST_WAIT_SECONDS);
        if ($answer->kind !== AgentClientToolAnswer::KIND_ANSWERED) {
            return static::buildUnansweredResponse($answer, static::LIST_WAIT_SECONDS, false);
        }
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->outcome = $answer->outcome;
        $responseDto->screenText = $answer->text;
        $responseDto->contentIsUntrusted = false;
        return $responseDto;
    }

    /**
     * Opens a screen for the user: only when the user asks to be taken there or shown something. Screens only: it never
     * saves, deletes or starts anything. Refused while the open form has unsaved changes.
     *
     * @example {"target": "order", "recordId": 10669, "tab": "items"}
     * @example {"target": "orders", "filters": ["customerId=3"]}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolSummary('Opens a screen (a key from listScreens, record id, tab, filters) in the user\'s client.')]
    #[MCPToolReturnsUntrustedContent('the opened screen\'s title and content are texts other people wrote')]
    public function openScreen(
        OpenScreenRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $target = trim((string)$requestDto->target);
        if ($target === '') {
            return static::buildLineResponse(static::MISSING_TARGET, 'MISSING_TARGET');
        }
        $arguments = ['target' => $target];
        $tab = trim((string)$requestDto->tab);
        $filters = [];
        foreach ((array)($requestDto->filters ?? []) as $filter) {
            $filter = trim((string)$filter);
            if ($filter !== '') {
                $filters[] = $filter;
            }
        }
        foreach (array_merge([$target], $tab !== '' ? [$tab] : []) as $token) {
            if (preg_match(static::TOKEN_PATTERN, $token) !== 1) {
                return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, $token === $target ? 'target' : 'tab'), 'INVALID_ARGUMENT');
            }
        }
        if (count($filters) > static::MAX_FILTERS) {
            return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'filters (at most ' . static::MAX_FILTERS . ')'), 'INVALID_ARGUMENT');
        }
        foreach ($filters as $filter) {
            [$filterKey, $filterValue] = array_pad(explode('=', $filter, 2), 2, null);
            if ($filterValue === null || preg_match(static::TOKEN_PATTERN, (string)$filterKey) !== 1 || mb_strlen($filterValue) > static::MAX_TOKEN_CHARS) {
                return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'filter ' . mb_substr($filter, 0, 40)), 'INVALID_ARGUMENT');
            }
        }
        if ($requestDto->recordId !== null && $requestDto->recordId > 0) {
            $arguments['recordId'] = $requestDto->recordId;
        }
        if ($tab !== '') {
            $arguments['tab'] = $tab;
        }
        if ($filters !== []) {
            $arguments['filters'] = $filters;
        }
        if ($requestDto->includeScreen === true) {
            $arguments['includeScreen'] = true;
        }
        $answer = $this->send($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_OPEN_TARGET, $arguments, static::OPEN_WAIT_SECONDS);
        if ($answer->kind !== AgentClientToolAnswer::KIND_ANSWERED) {
            return static::buildUnansweredResponse($answer, static::OPEN_WAIT_SECONDS, true);
        }
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->outcome = $answer->outcome;
        $title = (string)($answer->getDetailValue('title') ?? $target);
        $route = (string)($answer->getDetailValue('route') ?? '');
        $titleLine = sprintf(static::OPENED, $title, $route);
        if (trim((string)$answer->text) === '') {
            // the title can be a record name: it stays inside the untrusted mark
            $responseDto->screenText = $titleLine;
            return $responseDto;
        }
        $responseDto->lines[] = sprintf(static::SCREEN_FRAME, '');
        $responseDto->screenText = $titleLine . "\n" . $answer->text;
        return $responseDto;
    }

    /**
     * Points at elements on the user's screen with a calm outline and an optional short note. Use the references
     * of the newest getCurrentScreen (or openScreen with includeScreen), or anchors (a.…).
     *
     * @example {"references": ["f.endDate"], "note": "Change the end date here"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolSummary('Marks fields, buttons, rows or sections on the user\'s screen by their references, with a short note.')]
    #[MCPToolReturnsUntrustedContent('the marked elements are named by their labels on the screen')]
    public function highlightOnScreen(
        HighlightOnScreenRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $references = [];
        foreach ((array)($requestDto->references ?? []) as $reference) {
            $reference = trim((string)$reference);
            if ($reference === '') {
                continue;
            }
            if (preg_match(static::TOKEN_PATTERN, $reference) !== 1) {
                return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'reference ' . mb_substr($reference, 0, 40)), 'INVALID_ARGUMENT');
            }
            $references[] = $reference;
        }
        $references = array_values(array_unique($references));
        if ($references === [] || count($references) > static::MAX_REFERENCES) {
            return static::buildLineResponse(static::MISSING_REFERENCES, 'MISSING_REFERENCES');
        }
        $arguments = ['references' => $references];
        $note = trim(preg_replace('/\s+/u', ' ', (string)$requestDto->note) ?? '');
        if ($note !== '') {
            $arguments['note'] = mb_substr($note, 0, static::MAX_NOTE_CHARS);
        }
        $answer = $this->send($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_HIGHLIGHT, $arguments, static::HIGHLIGHT_WAIT_SECONDS);
        if ($answer->kind === AgentClientToolAnswer::KIND_ANSWERED || ($answer->kind === AgentClientToolAnswer::KIND_REFUSED && trim((string)$answer->text) !== '')) {
            $responseDto = new AgentUIToolResponseDto();
            $responseDto->outcome = $answer->outcome;
            $responseDto->screenText = $answer->text;
            if ($answer->kind === AgentClientToolAnswer::KIND_REFUSED) {
                $responseDto->closingLines[] = sprintf(static::NOT_DONE, static::describeRefusal((string)$answer->outcome));
            }
            return $responseDto;
        }
        return static::buildUnansweredResponse($answer, static::HIGHLIGHT_WAIT_SECONDS, false);
    }

    /**
     * @param AgentClientToolRequestsService $agentClientToolRequestsService
     * @param AgentToolCallProvenance $agentToolCallProvenance
     * @param string $requestType
     * @param array<string, mixed> $arguments
     * @param int $waitSeconds
     * @return AgentClientToolAnswer
     */
    protected function send(
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance,
        string $requestType,
        array $arguments,
        int $waitSeconds
    ): AgentClientToolAnswer {
        $conversationId = $agentToolCallProvenance->rootConversationId ?? $agentToolCallProvenance->conversationId;
        $accountId = AuthService::instance()->getAccount()?->id;
        if ($conversationId === null || $accountId === null) {
            return AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_NO_CLIENT);
        }
        return $agentClientToolRequestsService->requestAndWait(
            (int)$conversationId,
            (int)$accountId,
            $agentToolCallProvenance->toolCallId ?? null,
            $requestType,
            $arguments,
            $waitSeconds
        );
    }

    /**
     * The result of a request the client did not carry out.
     *
     * @param AgentClientToolAnswer $answer
     * @param int $waitSeconds
     * @param bool $isNavigation
     * @return AgentUIToolResponseDto
     */
    protected static function buildUnansweredResponse(AgentClientToolAnswer $answer, int $waitSeconds, bool $isNavigation): AgentUIToolResponseDto
    {
        return match ($answer->kind) {
            AgentClientToolAnswer::KIND_NO_CLIENT => static::buildLineResponse(static::NO_CLIENT, AgentClientToolAnswer::KIND_NO_CLIENT),
            AgentClientToolAnswer::KIND_BACKGROUND => static::buildLineResponse(static::BACKGROUND_CLIENT, AgentClientToolAnswer::KIND_BACKGROUND),
            AgentClientToolAnswer::KIND_NO_ANSWER => static::buildLineResponse(sprintf($isNavigation ? static::NO_ANSWER_OPEN : static::NO_ANSWER, $waitSeconds), AgentClientToolAnswer::KIND_NO_ANSWER),
            default => static::buildLineResponse(sprintf($isNavigation ? static::NOT_OPENED : static::NOT_DONE, static::describeRefusal((string)$answer->outcome)), (string)$answer->outcome),
        };
    }

    /**
     * @param string $outcome
     * @return string The sentence and next move of a refusal code
     */
    public static function describeRefusal(string $outcome): string
    {
        return static::REFUSALS[$outcome] ?? sprintf(static::REFUSED_OTHER, $outcome);
    }

    /**
     * @param string $line
     * @param string $outcome
     * @return AgentUIToolResponseDto
     */
    protected static function buildLineResponse(string $line, string $outcome): AgentUIToolResponseDto
    {
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->lines[] = $line;
        $responseDto->outcome = $outcome;
        return $responseDto;
    }
}
