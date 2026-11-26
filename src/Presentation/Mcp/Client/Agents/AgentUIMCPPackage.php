<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\AI\Services\Conversations\Realtime\AgentRealtimeChannelService;
use DDD\Infrastructure\Services\DDDService;
use Throwable;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Infrastructure\Cache\Cache;
use DDD\Infrastructure\Libs\Config;
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

    /**
     * @var string The line of an opened screen with a filter value its filter does not take (the client left it out and
     * names the filter and its values): the client's text, so it stays inside the untrusted mark
     */
    public const string FILTERS_NOT_SET = 'Filter not set: %s';

    /** @var string The server's closing line of such an answer */
    public const string FILTERS_NOT_SET_NOTE = 'A filter value its filter does not take was left out (named above). The screen is open without it; say so, or set a value the filter takes.';

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
        'UNKNOWN_FILTER_VALUE' => 'the filter does not take this value. Its values follow.',
        'NOT_ON_SCREEN' => 'none of the references is on the screen. Read the screen again with getCurrentScreen and take its references.',
        'EXPIRED_BEFORE_START' => 'the client received the request too late. Nothing was done.',
        'READ_FAILED' => 'the client could not read the screen. Answer from the data tools.',
    ];

    public const string REFUSED_OTHER = 'the client refused (%s).';

    public const string NOT_ENABLED = 'This request type is switched off on this platform. Nothing was sent to the client.';

    /** @var string The text format of getCurrentScreen (default) */
    public const string FORMAT_TEXT = 'text';

    /** @var string The picture format of getCurrentScreen */
    public const string FORMAT_PICTURE = 'picture';

    /** @var string[] The picture sizes (the client's presets with a pixel and a byte budget each) */
    public const array PICTURE_SIZES = ['small', 'medium', 'large'];

    /** @var string The picture size when none is named */
    public const string DEFAULT_PICTURE_SIZE = 'medium';

    /** @var string[] The render modes of a picture: colour, greyscale, machine (64 grey levels, 75 % size, half the bytes) */
    public const array RENDER_MODES = ['colour', 'greyscale', 'machine'];

    /** @var string The render mode when none is named */
    public const string DEFAULT_RENDER_MODE = 'colour';

    public const string PICTURE_FRAME = 'A picture of the user\'s screen as the client renders it now%s (%d×%d, %s). It shows layout, colours and charts; for values and references read the screen as text.';

    public const string PICTURE_MISSING = 'The client answered without a picture. Read the screen as text instead.';

    /** @var string[] What a picture of the screen cannot show — the same fixed list the npm capturer states */
    public const array PICTURE_LIMITATIONS = [
        'content of other origins (embedded frames, some images and fonts) can be blank or replaced',
        'video, canvas and WebGL content can be blank',
        'password fields show dots; fields the application marks secret or skipped are left out',
        'only what is in the browser window is shown; parts scrolled out of view are left out (the text read has them)',
    ];

    public const string PICTURE_LIMITATIONS_LINE = 'A picture cannot show: %s.';

    /** @var int How many screens openScreen opens for one message of the user unless `AI.agentUI.maxOpenScreenPerUserTurn` says otherwise */
    public const int DEFAULT_MAX_OPEN_SCREEN_PER_USER_TURN = 1;

    public const string OPEN_SCREEN_CAP_REACHED = 'Not opened: %d screen(s) were opened for this message of the user already. Say where the next step is and let the user ask for it.';

    /** @var string The outcome of a refused openScreen over the cap */
    public const string OUTCOME_OPEN_SCREEN_CAP = 'OPEN_SCREEN_CAP';

    /**
     * What the user sees right now: URL, title, visible table rows, form fields with unsaved values, tab, filters,
     * selection, validation messages, and short references for highlightOnScreen. For stored data use the data tools;
     * the ui note already names the open screen and record. Never write a value from this result without reading it
     * with the data tool first. `format: "picture"` returns an image of the screen instead (layout, colours, charts):
     * only when the text cannot answer.
     *
     * @example {}
     * @example {"focus": "f.description"}
     * @example {"format": "picture", "pictureSize": "small", "renderMode": "machine"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolSummary('Reads what the user sees on the open screen (unsaved values, visible rows, messages) as Markdown with references, or as a picture.')]
    #[MCPToolReturnsUntrustedContent('a screen shows texts other people wrote')]
    public function getCurrentScreen(
        GetCurrentScreenRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $format = strtolower(trim((string)$requestDto->format));
        if ($format === static::FORMAT_PICTURE) {
            return $this->readScreenPicture($requestDto, $agentClientToolRequestsService, $agentToolCallProvenance);
        }
        if ($format !== '' && $format !== static::FORMAT_TEXT) {
            return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'format (text or picture)'), 'INVALID_ARGUMENT');
        }
        $arguments = [];
        $focus = trim((string)$requestDto->focus);
        if ($focus !== '') {
            if (preg_match(static::TOKEN_PATTERN, $focus) !== 1) {
                return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'focus'), 'INVALID_ARGUMENT');
            }
            $arguments['focus'] = $focus;
        }
        $answer = static::sendClientToolRequest($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_READ_SCREEN, $arguments, static::READ_WAIT_SECONDS);
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
        $answer = static::sendClientToolRequest($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_LIST_TARGETS, $arguments, static::LIST_WAIT_SECONDS);
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
        $maxOpenScreenPerUserTurn = static::getMaxOpenScreenPerUserTurn();
        $openedScreensOfUserTurn = max(static::countOpenScreenCallsOfUserTurn($agentToolCallProvenance), static::readOpenedScreensOfUserTurn($agentToolCallProvenance));
        if ($openedScreensOfUserTurn >= $maxOpenScreenPerUserTurn) {
            return static::buildLineResponse(sprintf(static::OPEN_SCREEN_CAP_REACHED, $openedScreensOfUserTurn), static::OUTCOME_OPEN_SCREEN_CAP);
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
        $answer = static::sendClientToolRequest($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_OPEN_TARGET, $arguments, static::OPEN_WAIT_SECONDS);
        if ($answer->kind !== AgentClientToolAnswer::KIND_ANSWERED) {
            return static::buildUnansweredResponse($answer, static::OPEN_WAIT_SECONDS, true);
        }
        static::countOpenedScreenOfUserTurn($agentToolCallProvenance);
        return static::buildOpenedResponse($answer, $target);
    }

    /**
     * The result of an opened screen: everything the client wrote (the title, which can be a record name, the route,
     * a filter value it left out, the screen text) stays inside the untrusted mark; the lines around it are the
     * server's own.
     */
    public static function buildOpenedResponse(AgentClientToolAnswer $answer, string $target): AgentUIToolResponseDto
    {
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->outcome = $answer->outcome;
        $title = (string)($answer->getDetailValue('title') ?? $target);
        $route = (string)($answer->getDetailValue('route') ?? '');
        $openedLines = sprintf(static::OPENED, $title, $route);
        $filtersNotSet = trim((string)($answer->getDetailValue('filtersNotSet') ?? ''));
        if ($filtersNotSet !== '') {
            $openedLines .= "\n" . sprintf(static::FILTERS_NOT_SET, $filtersNotSet);
            $responseDto->closingLines[] = static::FILTERS_NOT_SET_NOTE;
        }
        if (trim((string)$answer->text) === '') {
            $responseDto->screenText = $openedLines;
            return $responseDto;
        }
        $responseDto->lines[] = sprintf(static::SCREEN_FRAME, '');
        $responseDto->screenText = $openedLines . "\n" . $answer->text;
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
        $answer = static::sendClientToolRequest($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_HIGHLIGHT, $arguments, static::HIGHLIGHT_WAIT_SECONDS);
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
     * A picture of the screen: the client captures it with the named size preset and render mode; the server checked
     * the bytes as untrusted ({@see AgentClientToolRequestsService::checkScreenPicture()}); the picture rides inline as an
     * image part ({@see AgentUIToolResponseDto::getResponseImages()}) and the tool picture store keeps it like any tool
     * picture.
     */
    protected function readScreenPicture(
        GetCurrentScreenRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $pictureSize = strtolower(trim((string)$requestDto->pictureSize));
        $pictureSize = $pictureSize === '' ? static::DEFAULT_PICTURE_SIZE : $pictureSize;
        if (!in_array($pictureSize, static::PICTURE_SIZES, true)) {
            return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'pictureSize (' . implode(', ', static::PICTURE_SIZES) . ')'), 'INVALID_ARGUMENT');
        }
        $renderMode = strtolower(trim((string)$requestDto->renderMode));
        $renderMode = $renderMode === '' ? static::DEFAULT_RENDER_MODE : ($renderMode === 'color' ? 'colour' : $renderMode);
        if (!in_array($renderMode, static::RENDER_MODES, true)) {
            return static::buildLineResponse(sprintf(static::INVALID_ARGUMENT, 'renderMode (' . implode(', ', static::RENDER_MODES) . ')'), 'INVALID_ARGUMENT');
        }
        $arguments = ['format' => static::FORMAT_PICTURE, 'pictureSize' => $pictureSize, 'renderMode' => $renderMode];
        $answer = static::sendClientToolRequest($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_READ_SCREEN, $arguments, static::READ_WAIT_SECONDS);
        if ($answer->kind !== AgentClientToolAnswer::KIND_ANSWERED) {
            return static::buildUnansweredResponse($answer, static::READ_WAIT_SECONDS, false);
        }
        if ($answer->picture === null) {
            return static::buildLineResponse(static::PICTURE_MISSING, 'PICTURE_MISSING');
        }
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->outcome = $answer->outcome;
        $responseDto->contentIsUntrusted = false;
        $responseDto->lines[] = sprintf(
            static::PICTURE_FRAME,
            $answer->getDetailValue('visible') === false ? static::CLIENT_IN_BACKGROUND : '',
            $answer->picture->width,
            $answer->picture->height,
            $renderMode
        );
        $responseDto->lines[] = sprintf(static::PICTURE_LIMITATIONS_LINE, implode('; ', static::PICTURE_LIMITATIONS));
        $responseDto->setScreenPicture($answer->picture);
        return $responseDto;
    }

    /** How many screens openScreen opens for one message of the user (`AI.agentUI.maxOpenScreenPerUserTurn`, at least 1) */
    public static function getMaxOpenScreenPerUserTurn(): int
    {
        $settings = Config::get('AI.agentUI');
        $configuredMaximum = is_array($settings) ? ($settings['maxOpenScreenPerUserTurn'] ?? null) : null;
        return is_int($configuredMaximum) && $configuredMaximum > 0 ? $configuredMaximum : static::DEFAULT_MAX_OPEN_SCREEN_PER_USER_TURN;
    }

    /**
     * The openScreen calls of the owner's thread after the user's message this call answers (the call itself left
     * out, a failed call not counted); 0 where there is no owner thread to read.
     */
    public static function countOpenScreenCallsOfUserTurn(AgentToolCallProvenance $agentToolCallProvenance): int
    {
        return static::countToolCallsOfUserTurn($agentToolCallProvenance, 'openScreen');
    }

    /**
     * The calls of the tool `$toolName` in the owner's thread after the user's message this call answers (the call
     * itself left out, a failed call not counted); 0 where there is no owner thread to read.
     */
    public static function countToolCallsOfUserTurn(AgentToolCallProvenance $agentToolCallProvenance, string $toolName): int
    {
        $ownerThreadMessages = $agentToolCallProvenance->getOwnerThreadMessages();
        $sourceUserMessageIndex = $agentToolCallProvenance->sourceUserMessage?->indexInConversation ?? null;
        if ($ownerThreadMessages === null || $sourceUserMessageIndex === null) {
            return 0;
        }
        $toolNamePattern = '/(^|[._])' . preg_quote($toolName, '/') . '$/';
        $openScreenCalls = 0;
        foreach ($ownerThreadMessages->getElements() as $conversationMessage) {
            if (($conversationMessage->indexInConversation ?? -1) <= $sourceUserMessageIndex
                || ($conversationMessage->messageType ?? null) !== AIConversationMessage::MESSAGE_TOOL_CALL
                || !isset($conversationMessage->toolCall)
            ) {
                continue;
            }
            $toolCall = $conversationMessage->toolCall;
            if (preg_match($toolNamePattern, (string)($toolCall->name ?? '')) !== 1
                || ($toolCall->status ?? null) === AIConversationMessage::STATUS_FAILED
                || ($agentToolCallProvenance->toolCallId !== null && $toolCall->toolCallId === $agentToolCallProvenance->toolCallId)
            ) {
                continue;
            }
            $openScreenCalls++;
        }
        return $openScreenCalls;
    }

    /** @var string The shared-cache counter of the screens opened for one message of the user */
    public const string OPENED_SCREENS_KEY_PREFIX = 'agent-ui-opened-screens-';

    /** @var int How long that counter lives */
    public const int OPENED_SCREENS_TTL_SECONDS = 3600;

    /**
     * The screens opened for the user's message so far by the counter in the shared cache — it also sees the calls of
     * the current step, whose rows are not written yet; 0 without a shared cache or a source message.
     */
    public static function readOpenedScreensOfUserTurn(AgentToolCallProvenance $agentToolCallProvenance): int
    {
        return static::readCounterOfUserTurn($agentToolCallProvenance, static::OPENED_SCREENS_KEY_PREFIX);
    }

    /** Counts one opened screen for the user's message (atomic; nothing without a shared cache) */
    protected static function countOpenedScreenOfUserTurn(AgentToolCallProvenance $agentToolCallProvenance): void
    {
        static::incrementCounterOfUserTurn($agentToolCallProvenance, static::OPENED_SCREENS_KEY_PREFIX);
    }

    /**
     * A counter of the shared cache for the user's message this call answers (`$keyPrefix` + the message id) — it also
     * sees the calls of the current step, whose rows are not written yet; 0 without a shared cache or a source message.
     */
    public static function readCounterOfUserTurn(AgentToolCallProvenance $agentToolCallProvenance, string $keyPrefix): int
    {
        $sourceUserMessageId = $agentToolCallProvenance->getSourceUserMessageId();
        if ($sourceUserMessageId === null) {
            return 0;
        }
        try {
            $count = static::getSharedCache()?->get($keyPrefix . $sourceUserMessageId);
        } catch (Throwable) {
            return 0;
        }
        return is_numeric($count) ? (int)$count : 0;
    }

    /** Adds one to a counter of the user's message (atomic; nothing without a shared cache) */
    public static function incrementCounterOfUserTurn(AgentToolCallProvenance $agentToolCallProvenance, string $keyPrefix): void
    {
        $sourceUserMessageId = $agentToolCallProvenance->getSourceUserMessageId();
        if ($sourceUserMessageId === null) {
            return;
        }
        try {
            static::getSharedCache()?->increment($keyPrefix . $sourceUserMessageId, 1, static::OPENED_SCREENS_TTL_SECONDS);
        } catch (Throwable) {
            // a cache without counters: the rows of the turn still cap the next steps
        }
    }

    protected static function getSharedCache(): ?Cache
    {
        /** @var AgentRealtimeChannelService $agentRealtimeChannelService */
        $agentRealtimeChannelService = DDDService::instance()->getService(AgentRealtimeChannelService::class);
        return $agentRealtimeChannelService->getSharedCache();
    }

    /**
     * @param AgentClientToolRequestsService $agentClientToolRequestsService
     * @param AgentToolCallProvenance $agentToolCallProvenance
     * @param string $requestType
     * @param array<string, mixed> $arguments
     * @param int $waitSeconds
     * @return AgentClientToolAnswer
     */
    public static function sendClientToolRequest(
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
    public static function buildUnansweredResponse(AgentClientToolAnswer $answer, int $waitSeconds, bool $isNavigation): AgentUIToolResponseDto
    {
        return match ($answer->kind) {
            AgentClientToolAnswer::KIND_NO_CLIENT => static::buildLineResponse(static::NO_CLIENT, AgentClientToolAnswer::KIND_NO_CLIENT),
            AgentClientToolAnswer::KIND_BACKGROUND => static::buildLineResponse(static::BACKGROUND_CLIENT, AgentClientToolAnswer::KIND_BACKGROUND),
            AgentClientToolAnswer::KIND_NOT_ENABLED => static::buildLineResponse(static::NOT_ENABLED, AgentClientToolAnswer::KIND_NOT_ENABLED),
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
    public static function buildLineResponse(string $line, string $outcome): AgentUIToolResponseDto
    {
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->lines[] = $line;
        $responseDto->outcome = $outcome;
        return $responseDto;
    }
}
