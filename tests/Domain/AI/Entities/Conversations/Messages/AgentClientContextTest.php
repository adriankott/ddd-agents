<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContext;
use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContextRouteParameter;
use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContextRouteParameters;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The client context's caps: every field optional, an input over a cap or with control characters is named (the
 * service refuses it with HTTP 400), never cut; the wire's list of `{name, value}` objects hydrates the route
 * parameters; the message's context load selects the column.
 */
class AgentClientContextTest extends UnitTestCase
{
    public function testAContextWithinItsCapsHasNoViolation(): void
    {
        $agentClientContext = $this->buildClientContext('/tickets/42', ['ticketId' => '42'], 'ticket', '42', 'details');

        self::assertNull($agentClientContext->findCapViolation());
        self::assertFalse($agentClientContext->isEmpty());
        self::assertSame('ticketId=42', $agentClientContext->routeParameters->toQueryText());
        self::assertTrue((new AgentClientContext())->isEmpty());
        self::assertNull((new AgentClientContext())->findCapViolation());
    }

    public function testEveryCapIsNamedAndNothingIsCut(): void
    {
        $overCapContexts = [
            'clientContext.routePath is longer than 512 characters.' => $this->buildClientContext('/' . str_repeat('a', 512)),
            'clientContext.recordKind is longer than 64 characters.' => $this->buildClientContext(null, [], str_repeat('k', 65)),
            'clientContext.recordId is longer than 128 characters.' => $this->buildClientContext(null, [], null, str_repeat('1', 129)),
            'clientContext.tab is longer than 64 characters.' => $this->buildClientContext(null, [], null, null, str_repeat('t', 65)),
            'clientContext.routeParameters is longer than 512 characters.' => $this->buildClientContext(null, ['query' => str_repeat('q', 510)]),
            'clientContext.routePath contains control characters.' => $this->buildClientContext("/tickets\n42"),
            'clientContext.routeParameters contains control characters.' => $this->buildClientContext(null, ['tab' => "a\tb"]),
            'clientContext.routeParameters holds a parameter without a name.' => $this->buildClientContext(null, [' ' => 'x']),
        ];
        foreach ($overCapContexts as $expectedViolation => $agentClientContext) {
            self::assertSame($expectedViolation, $agentClientContext->findCapViolation());
        }
        $manyParameters = [];
        for ($parameterNumber = 1; $parameterNumber <= AgentClientContext::MAX_ROUTE_PARAMETER_COUNT + 1; $parameterNumber++) {
            $manyParameters['p' . $parameterNumber] = '1';
        }
        self::assertSame('clientContext.routeParameters holds more than 20 parameters.', $this->buildClientContext(null, $manyParameters)->findCapViolation());
        self::assertNull($this->buildClientContext('/' . str_repeat('a', 511))->findCapViolation(), 'exactly at the cap is within it');
    }

    public function testTheWireListOfNameAndValueHydratesTheRouteParameters(): void
    {
        $wireObject = json_decode('{"routePath":"/tickets/42","routeParameters":[{"name":"ticketId","value":"42"},{"name":"view","value":"compact"}],"recordKind":"ticket","recordId":"42","tab":"details"}');
        $agentClientContext = new AgentClientContext();
        $agentClientContext->setPropertiesFromObject($wireObject);

        self::assertSame('/tickets/42', $agentClientContext->routePath);
        self::assertInstanceOf(AgentClientContextRouteParameters::class, $agentClientContext->routeParameters);
        self::assertSame('ticketId=42&view=compact', $agentClientContext->routeParameters->toQueryText());
        self::assertSame('details', $agentClientContext->tab);
    }

    public function testTheContextLoadSelectsTheColumnAndAnApplicationsMessageClassExtendsTheList(): void
    {
        self::assertContains('clientContext', AIConversationMessage::getContextLoadColumns());
        self::assertSame(AIConversationMessage::CONTEXT_LOAD_COLUMNS, AIConversationMessage::getContextLoadColumns());
        self::assertSame(
            [...AIConversationMessage::CONTEXT_LOAD_COLUMNS, ApplicationMessageWithOwnColumnDouble::APPLICATION_COLUMN],
            ApplicationMessageWithOwnColumnDouble::getContextLoadColumns()
        );
        self::assertFalse((new \ReflectionProperty(AIConversationMessage::class, 'clientContext'))->hasDefaultValue(), 'a projected load leaves it unset, a later write skips it');
    }

    /**
     * @param array<string, string> $routeParameterValuesByName
     */
    protected function buildClientContext(
        ?string $routePath = null,
        array $routeParameterValuesByName = [],
        ?string $recordKind = null,
        ?string $recordId = null,
        ?string $tab = null
    ): AgentClientContext {
        $agentClientContext = new AgentClientContext();
        $agentClientContext->routePath = $routePath;
        if ($routeParameterValuesByName !== []) {
            $agentClientContext->routeParameters = new AgentClientContextRouteParameters();
            foreach ($routeParameterValuesByName as $parameterName => $parameterValue) {
                $agentClientContextRouteParameter = AgentClientContextRouteParameter::fromNameAndValue((string)$parameterName, $parameterValue);
                $agentClientContext->routeParameters->add($agentClientContextRouteParameter);
            }
        }
        $agentClientContext->recordKind = $recordKind;
        $agentClientContext->recordId = $recordId;
        $agentClientContext->tab = $tab;
        return $agentClientContext;
    }
}
