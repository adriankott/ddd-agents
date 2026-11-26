<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPRequiresAccountRights;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Services\ClientTools\AgentClientToolRequestsService;
use DDD\Domain\AI\Services\ClientTools\AgentUIClientToolRequestTypeProviderService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Agents\Dtos\ActOnScreenRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentUIToolResponseDto;
use Throwable;

/**
 * The typed actions of `Agent.UI` on the user's open screen (request type `ACT_ON_SCREEN`): available only while the
 * host switched the request type on (`AI.agentUI.screenActionRequests`).
 *
 * What an action is, is a closed list ({@see self::ACTIONS}): set the screen's own URL filters, sort a table, open a
 * row, switch a tab, press a button. The agent never names a selector: a filter is a key the screen's target
 * declares, every other action takes a reference of the newest screen read, and the client acts only on an element
 * the screen DECLARES for that action (`data-agent-action`). A button is pressed only when it is declared with the
 * effect `view`; a button without a declaration (`b.…`) is refused here, before anything is sent. So nothing of this
 * package saves, deletes or sends: a write stays a data tool with its own contract, or the user's own click.
 *
 * Effect READ (the view of the user's own client changes, no stored data), never reused within a turn, never
 * parallel. The client marks the element before it acts, so the user sees what happens. At most
 * `AI.agentUI.maxScreenActionsPerUserTurn` actions are carried out for one message of the user.
 */
#[MCPRequiresAccountRights(AgentAccountRights::USE_UI)]
#[MCPToolExecutionTimeout(25, 'waits for the user\'s client (at most 15 s)')]
class AgentUIActionsMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.ui.actions';

    public static ?string $description = 'Act on the user\'s open screen: set its filters, sort, open a row, switch a tab, press a declared button.';

    public const string ACTION_SET_FILTERS = 'setFilters';

    public const string ACTION_SORT = 'sort';

    public const string ACTION_OPEN_ROW = 'openRow';

    public const string ACTION_SWITCH_TAB = 'switchTab';

    public const string ACTION_PRESS = 'press';

    /** @var string[] The actions, a closed list (the client's `SCREEN_ACTION_KINDS`) */
    public const array ACTIONS = [self::ACTION_SET_FILTERS, self::ACTION_SORT, self::ACTION_OPEN_ROW, self::ACTION_SWITCH_TAB, self::ACTION_PRESS];

    /** @var string[] The kind of reference each action takes (the prefix of the reader's reference) */
    public const array REFERENCE_PREFIX_OF_ACTION = [
        self::ACTION_SORT => 'col.',
        self::ACTION_OPEN_ROW => 'row.',
        self::ACTION_SWITCH_TAB => 't.',
        self::ACTION_PRESS => 'act.',
    ];

    /** @var string[] The directions of the action sort */
    public const array SORT_DIRECTIONS = ['asc', 'desc', 'none'];

    /** @var int How long actOnScreen waits (the mark's beat plus up to 6 s for the screen to settle) */
    public const int ACT_WAIT_SECONDS = 15;

    /** @var int How many actions are carried out for one message of the user unless `AI.agentUI.maxScreenActionsPerUserTurn` says otherwise */
    public const int DEFAULT_MAX_SCREEN_ACTIONS_PER_USER_TURN = 3;

    /** @var string The shared-cache counter of the actions carried out for one message of the user */
    public const string SCREEN_ACTIONS_KEY_PREFIX = 'agent-ui-screen-actions-';

    /** @var string The outcome of a refused action over the cap */
    public const string OUTCOME_SCREEN_ACTION_CAP = 'SCREEN_ACTION_CAP';

    public const string DONE = 'Done on the user\'s screen (the user saw it happen).';

    public const string NOW_ON = 'The screen is now %s.';

    public const string DONE_WITH_SCREEN = 'Done on the user\'s screen. Below: what was done, then the screen as it is now. Values can be unsaved: read a value with the data tool before you write it.';

    public const string SCREEN_ACTION_CAP_REACHED = 'Not done: %d action(s) ran on the screen for this message of the user already. Say what the next step is and let the user ask for it.';

    public const string MISSING_ACTION = 'Nothing sent to the client: action is missing or unknown (setFilters, sort, openRow, switchTab, press).';

    public const string MISSING_FILTERS = 'Nothing sent to the client: setFilters needs 1 to %d filters as key=value (an empty value clears one). The keys stand in the Filters line of getCurrentScreen.';

    public const string MISSING_REFERENCE = 'Nothing sent to the client: %s needs a reference that starts with "%s" from the newest getCurrentScreen.';

    public const string MISSING_DIRECTION = 'Nothing sent to the client: sort needs direction asc, desc or none.';

    public const string NOT_OFFERED_BUTTON = 'Not done: %s is not a button the screen offers to you (those are act.… in the Actions line). It can save, delete or send: make the change with your own data tool, or mark it with highlightOnScreen and let the user press it.';

    /** @var string[] The sentence and next move per refusal code of an action (the other codes: {@see AgentUIMCPPackage::REFUSALS}) */
    public const array ACTION_REFUSALS = [
        'ACTIONS_UNAVAILABLE' => 'this client cannot act on the screen. Open the screen with openScreen, or tell the user where to click.',
        'UNKNOWN_ACTION' => 'the client does not know this action.',
        'NO_FILTERS' => 'the open screen has no filters. listScreens names the filters of every screen.',
        'UNKNOWN_FILTER' => 'the open screen has no such filter. Its Filters line in getCurrentScreen names them.',
        'MISSING_PARAMETER' => 'the action lacks its reference, filters or direction.',
        'NOT_ON_SCREEN' => 'the reference is not on the screen. Read the screen again with getCurrentScreen and take its references.',
        'NOT_DECLARED' => 'the screen does not offer this element to you. It can change data: make the change with your own data tool, or mark the element with highlightOnScreen and let the user press it. Do not try another reference for it.',
        'WRITE_ACTION' => 'this button saves, deletes or sends, and you never press such a button. Make the change with your own data tool, or mark the button with highlightOnScreen and let the user press it.',
        'DISABLED' => 'the element is disabled on the screen. Tell the user.',
        'NO_EFFECT' => 'the screen did not change. Read it again with getCurrentScreen before you try anything else.',
    ];

    /**
     * @var string[] The next move per action when the screen does not declare the element of an action that only
     * navigates: `openScreen` opens the same record or tab without it (a list or a tab bar the application built
     * without the declaring components)
     */
    public const array NOT_DECLARED_NAVIGATION_REFUSALS = [
        self::ACTION_OPEN_ROW => 'the screen does not offer this row to you. Open its record with openScreen instead (the record\'s target from listScreens and the row\'s record id). Do not try another reference for it.',
        self::ACTION_SWITCH_TAB => 'the screen does not offer this tab to you. Open the tab with openScreen instead (the open screen\'s target, its record id and the tab). Do not try another reference for it.',
        self::ACTION_SORT => 'the screen does not offer this column to you for sorting. If its Filters line names a filter sort, set that with setFilters; otherwise tell the user. Do not try another reference for it.',
    ];

    /** Offered only while the host switched on the typed actions */
    public static function isAvailable(): bool
    {
        try {
            /** @var AgentUIClientToolRequestTypeProviderService $agentUIClientToolRequestTypeProviderService */
            $agentUIClientToolRequestTypeProviderService = DDDService::instance()->getService(AgentUIClientToolRequestTypeProviderService::class);
            return $agentUIClientToolRequestTypeProviderService->isScreenActionRequestsEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Acts on the screen the user has open, visibly (the element is marked, then the action runs): only when the user
     * asks for it. `setFilters`: `filters` as key=value of the screen's own filters (the Filters line of
     * getCurrentScreen), an empty value clears one. `sort`: `reference` col.…, `direction` asc, desc or none.
     * `openRow`: `reference` row.…. `switchTab`: `reference` t.…. `press`: `reference` act.… (only the buttons of the
     * Actions line). References come from the newest getCurrentScreen. It never saves, deletes or sends: for a change
     * use your data tools, or mark the button with highlightOnScreen and let the user press it.
     *
     * @example {"action": "setFilters", "filters": ["status=OPEN", "priority="]}
     * @example {"action": "sort", "reference": "col.created", "direction": "desc"}
     * @example {"action": "openRow", "reference": "row.10669", "includeScreen": true}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ, resultIsReusedWithinTurn: false)]
    #[MCPToolSummary('Sets filters, sorts, opens a row, switches a tab or presses a declared button on the user\'s open screen; never saves, deletes or sends.')]
    #[MCPToolReturnsUntrustedContent('the acted-on elements are named by their labels on the screen')]
    public function actOnScreen(
        ActOnScreenRequestDto $requestDto,
        AgentClientToolRequestsService $agentClientToolRequestsService,
        AgentToolCallProvenance $agentToolCallProvenance
    ): AgentUIToolResponseDto {
        $arguments = static::buildActionArguments($requestDto);
        if ($arguments instanceof AgentUIToolResponseDto) {
            return $arguments;
        }
        $screenActionsOfUserTurn = AgentUIMCPPackage::readCounterOfUserTurn($agentToolCallProvenance, static::SCREEN_ACTIONS_KEY_PREFIX);
        if ($screenActionsOfUserTurn >= static::getMaxScreenActionsPerUserTurn()) {
            return AgentUIMCPPackage::buildLineResponse(sprintf(static::SCREEN_ACTION_CAP_REACHED, $screenActionsOfUserTurn), static::OUTCOME_SCREEN_ACTION_CAP);
        }
        $answer = AgentUIMCPPackage::sendClientToolRequest($agentClientToolRequestsService, $agentToolCallProvenance, AgentClientToolRequest::TYPE_ACT_ON_SCREEN, $arguments, static::ACT_WAIT_SECONDS);
        if ($answer->kind === AgentClientToolAnswer::KIND_REFUSED) {
            return static::buildRefusedResponse($answer, (string)$requestDto->action);
        }
        if ($answer->kind !== AgentClientToolAnswer::KIND_ANSWERED) {
            return AgentUIMCPPackage::buildUnansweredResponse($answer, static::ACT_WAIT_SECONDS, true);
        }
        AgentUIMCPPackage::incrementCounterOfUserTurn($agentToolCallProvenance, static::SCREEN_ACTIONS_KEY_PREFIX);
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->outcome = $answer->outcome;
        $includesScreen = ($arguments['includeScreen'] ?? false) === true;
        $responseDto->lines[] = $includesScreen ? static::DONE_WITH_SCREEN : static::DONE;
        // the client's sentence names labels of the screen, the route can carry a filter text: both stay inside the untrusted mark
        $route = trim((string)($answer->getDetailValue('route') ?? ''));
        $responseDto->screenText = trim((string)$answer->text) . ($route !== '' && !$includesScreen ? "\n" . sprintf(static::NOW_ON, $route) : '');
        return $responseDto;
    }

    /**
     * The arguments of the client request from the tool's arguments, or the refusal of what the server can decide
     * alone: an unknown action, a reference of the wrong kind, a button the screen does not offer (`b.…`).
     *
     * @return array<string, mixed>|AgentUIToolResponseDto
     */
    public static function buildActionArguments(ActOnScreenRequestDto $requestDto): array|AgentUIToolResponseDto
    {
        $action = trim((string)$requestDto->action);
        if (!in_array($action, static::ACTIONS, true)) {
            return AgentUIMCPPackage::buildLineResponse(static::MISSING_ACTION, 'INVALID_ARGUMENT');
        }
        $arguments = ['action' => $action];
        if ($requestDto->includeScreen === true) {
            $arguments['includeScreen'] = true;
        }
        if ($action === static::ACTION_SET_FILTERS) {
            $filters = [];
            foreach ((array)($requestDto->filters ?? []) as $filter) {
                $filter = trim((string)$filter);
                if ($filter === '') {
                    continue;
                }
                [$filterKey, $filterValue] = array_pad(explode('=', $filter, 2), 2, null);
                if ($filterValue === null || preg_match(AgentUIMCPPackage::TOKEN_PATTERN, (string)$filterKey) !== 1 || mb_strlen($filterValue) > AgentUIMCPPackage::MAX_TOKEN_CHARS) {
                    return AgentUIMCPPackage::buildLineResponse(sprintf(AgentUIMCPPackage::INVALID_ARGUMENT, 'filter ' . mb_substr($filter, 0, 40)), 'INVALID_ARGUMENT');
                }
                $filters[] = $filterKey . '=' . trim($filterValue);
            }
            if ($filters === [] || count($filters) > AgentUIMCPPackage::MAX_FILTERS) {
                return AgentUIMCPPackage::buildLineResponse(sprintf(static::MISSING_FILTERS, AgentUIMCPPackage::MAX_FILTERS), 'MISSING_FILTERS');
            }
            $arguments['filters'] = $filters;
            return $arguments;
        }
        $reference = trim((string)$requestDto->reference);
        $referencePrefix = static::REFERENCE_PREFIX_OF_ACTION[$action];
        if ($reference !== '' && preg_match(AgentUIMCPPackage::TOKEN_PATTERN, $reference) !== 1) {
            return AgentUIMCPPackage::buildLineResponse(sprintf(AgentUIMCPPackage::INVALID_ARGUMENT, 'reference'), 'INVALID_ARGUMENT');
        }
        if ($action === static::ACTION_PRESS && $reference !== '' && !str_starts_with($reference, $referencePrefix)) {
            return AgentUIMCPPackage::buildLineResponse(sprintf(static::NOT_OFFERED_BUTTON, $reference), 'NOT_DECLARED');
        }
        if (!str_starts_with($reference, $referencePrefix)) {
            return AgentUIMCPPackage::buildLineResponse(sprintf(static::MISSING_REFERENCE, $action, $referencePrefix), 'MISSING_REFERENCE');
        }
        $arguments['reference'] = $reference;
        if ($action === static::ACTION_SORT) {
            $direction = strtolower(trim((string)$requestDto->direction));
            if (!in_array($direction, static::SORT_DIRECTIONS, true)) {
                return AgentUIMCPPackage::buildLineResponse(static::MISSING_DIRECTION, 'MISSING_DIRECTION');
            }
            $arguments['direction'] = $direction;
        }
        return $arguments;
    }

    /** The result of an action the client refused: the sentence of its code, and what the client said about it (labels: untrusted) */
    public static function buildRefusedResponse(AgentClientToolAnswer $answer, ?string $action = null): AgentUIToolResponseDto
    {
        $responseDto = new AgentUIToolResponseDto();
        $responseDto->outcome = $answer->outcome;
        $responseDto->lines[] = sprintf(AgentUIMCPPackage::NOT_DONE, static::describeRefusal((string)$answer->outcome, $action));
        if (trim((string)$answer->text) !== '') {
            $responseDto->screenText = $answer->text;
        }
        return $responseDto;
    }

    /**
     * @return string The sentence and next move of a refusal code of an action; an element the screen does not declare
     * for an action that only navigates points at `openScreen` ({@see self::NOT_DECLARED_NAVIGATION_REFUSALS})
     */
    public static function describeRefusal(string $outcome, ?string $action = null): string
    {
        if ($outcome === 'NOT_DECLARED' && $action !== null && isset(static::NOT_DECLARED_NAVIGATION_REFUSALS[$action])) {
            return static::NOT_DECLARED_NAVIGATION_REFUSALS[$action];
        }
        return static::ACTION_REFUSALS[$outcome] ?? AgentUIMCPPackage::describeRefusal($outcome);
    }

    /** How many actions are carried out for one message of the user (`AI.agentUI.maxScreenActionsPerUserTurn`, at least 1) */
    public static function getMaxScreenActionsPerUserTurn(): int
    {
        $settings = Config::get(AgentUIClientToolRequestTypeProviderService::CONFIG_KEY);
        $configuredMaximum = is_array($settings) ? ($settings['maxScreenActionsPerUserTurn'] ?? null) : null;
        return is_int($configuredMaximum) && $configuredMaximum > 0 ? $configuredMaximum : static::DEFAULT_MAX_SCREEN_ACTIONS_PER_USER_TURN;
    }
}
