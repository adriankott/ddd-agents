<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolAnswer;
use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Services\ClientTools\AgentUIClientToolRequestTypeProviderService;
use DDD\Presentation\Mcp\Client\Agents\AgentUIActionsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\Dtos\ActOnScreenRequestDto;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentUIToolResponseDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * `Agent.UI` actions (`actOnScreen`): a closed list of actions, a filter as key=value, every other action with a
 * reference of its own kind; a button the screen does not offer (`b.…`) is refused before anything is sent; the
 * request type and the package are off unless the host switches them on; a refusal of the client reads as "not done"
 * with the next move.
 */
class AgentUIActionsMCPPackageTest extends UnitTestCase
{
    public function testAnActionIsOneOfTheClosedListAndCarriesOnlyItsOwnArguments(): void
    {
        self::assertSame(
            ['action' => 'setFilters', 'filters' => ['status=OPEN', 'priority=']],
            AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('setFilters', filters: ['status=OPEN', ' priority= ', '']))
        );
        self::assertSame(
            ['action' => 'sort', 'reference' => 'col.created', 'direction' => 'desc'],
            AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('sort', 'col.created', direction: 'DESC'))
        );
        self::assertSame(
            ['action' => 'openRow', 'includeScreen' => true, 'reference' => 'row.10669'],
            AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('openRow', 'row.10669', includeScreen: true))
        );
        self::assertSame(['action' => 'switchTab', 'reference' => 't.winners'], AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('switchTab', 't.winners')));
        self::assertSame(['action' => 'press', 'reference' => 'act.load-more'], AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('press', 'act.load-more')));
    }

    public function testWhatTheServerCanDecideAloneIsRefusedBeforeAnythingIsSent(): void
    {
        $refusals = [
            'INVALID_ARGUMENT' => static::buildRequest('click', 'b.save'),
            'MISSING_FILTERS' => static::buildRequest('setFilters'),
            'MISSING_REFERENCE' => static::buildRequest('openRow', 'b.open'),
            'MISSING_DIRECTION' => static::buildRequest('sort', 'col.created', direction: 'newest'),
            'NOT_DECLARED' => static::buildRequest('press', 'b.delete'),
        ];
        foreach ($refusals as $expectedOutcome => $requestDto) {
            $response = AgentUIActionsMCPPackage::buildActionArguments($requestDto);
            self::assertInstanceOf(AgentUIToolResponseDto::class, $response);
            self::assertSame($expectedOutcome, $response->outcome);
        }
        $selectorResponse = AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('press', 'button.danger > span'));
        self::assertInstanceOf(AgentUIToolResponseDto::class, $selectorResponse);
        self::assertSame('INVALID_ARGUMENT', $selectorResponse->outcome, 'a selector is no reference');
        $badFilterResponse = AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('setFilters', filters: ['status']));
        self::assertInstanceOf(AgentUIToolResponseDto::class, $badFilterResponse);
        self::assertSame('INVALID_ARGUMENT', $badFilterResponse->outcome);
        $pressResponse = AgentUIActionsMCPPackage::buildActionArguments(static::buildRequest('press', 'b.delete'));
        self::assertInstanceOf(AgentUIToolResponseDto::class, $pressResponse);
        self::assertStringContainsString('your own data tool', $pressResponse->lines[0]);
        self::assertStringContainsString('let the user press it', $pressResponse->lines[0]);
    }

    public function testTheActionsAreOffUnlessTheHostSwitchesThemOn(): void
    {
        $typeProvider = new AgentUIClientToolRequestTypeProviderService();
        $actionType = null;
        foreach ($typeProvider->getClientToolRequestTypes()->getElements() as $agentClientToolRequestType) {
            if ($agentClientToolRequestType->name === AgentClientToolRequest::TYPE_ACT_ON_SCREEN) {
                $actionType = $agentClientToolRequestType;
            }
        }

        self::assertNotNull($actionType, 'the type is registered');
        self::assertFalse($actionType->isEnabled);
        self::assertFalse($typeProvider->isScreenActionRequestsEnabled());
        self::assertFalse(AgentUIActionsMCPPackage::isAvailable());
        self::assertSame(3, AgentUIActionsMCPPackage::getMaxScreenActionsPerUserTurn());
        self::assertContains(AgentClientToolRequest::TYPE_ACT_ON_SCREEN, AgentClientToolRequest::TYPES);
    }

    public function testARefusalOfTheClientNamesTheNextMoveAndKeepsItsLabelsUntrusted(): void
    {
        $answer = AgentClientToolAnswer::ofKind(AgentClientToolAnswer::KIND_REFUSED);
        $answer->outcome = 'WRITE_ACTION';
        $answer->text = 'act.archive-all "Archive all"';

        $response = AgentUIActionsMCPPackage::buildRefusedResponse($answer);

        self::assertSame('WRITE_ACTION', $response->outcome);
        self::assertStringStartsWith('Not done: this button saves, deletes or sends', $response->lines[0]);
        self::assertSame('act.archive-all "Archive all"', $response->screenText);
        self::assertTrue($response->contentIsUntrusted);
        self::assertStringContainsString('Do not try another reference', AgentUIActionsMCPPackage::describeRefusal('NOT_DECLARED'));
        self::assertStringContainsString('unsaved changes', AgentUIActionsMCPPackage::describeRefusal('UNSAVED_CHANGES'), 'the other codes read as for openScreen');
    }

    /** @param string[]|null $filters */
    protected static function buildRequest(string $action, ?string $reference = null, ?array $filters = null, ?string $direction = null, ?bool $includeScreen = null): ActOnScreenRequestDto
    {
        $requestDto = new ActOnScreenRequestDto();
        $requestDto->action = $action;
        $requestDto->reference = $reference;
        $requestDto->filters = $filters;
        $requestDto->direction = $direction;
        $requestDto->includeScreen = $includeScreen;
        return $requestDto;
    }
}
