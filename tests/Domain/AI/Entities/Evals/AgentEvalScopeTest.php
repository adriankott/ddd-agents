<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Evals;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Evals\AgentEvalScope;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The scope of one evaluation run: the agent space (stored or not yet), the account that owns it and the application's
 * own values; the only change it takes is from "not stored" to "stored".
 */
class AgentEvalScopeTest extends UnitTestCase
{
    protected function buildAccount(int $accountId): Account
    {
        $account = new Account();
        $account->id = $accountId;
        return $account;
    }

    protected function buildAgentSpace(?int $agentSpaceId): AgentSpace
    {
        $agentSpace = new AgentSpace();
        if ($agentSpaceId !== null) {
            $agentSpace->id = $agentSpaceId;
        }
        return $agentSpace;
    }

    public function testAScopeOfAStoredAgentSpaceCarriesItsIdItsOwnerAndTheApplicationsValues(): void
    {
        $agentSpace = $this->buildAgentSpace(17);
        $agentEvalScope = AgentEvalScope::forAgentSpace($agentSpace, $this->buildAccount(5), ['externalKey' => 4100, 'emptyValue' => null]);

        self::assertSame(17, $agentEvalScope->getAgentSpaceId());
        self::assertSame($agentSpace, $agentEvalScope->getAgentSpace());
        self::assertSame($agentSpace, $agentEvalScope->getStoredAgentSpace());
        self::assertTrue($agentEvalScope->hasStoredAgentSpace());
        self::assertSame(5, $agentEvalScope->getOwnerAccountId());
        self::assertSame(5, (int)$agentEvalScope->getOwnerAccount()->id);
        self::assertSame(4100, $agentEvalScope->getApplicationValue('externalKey'));
        self::assertTrue($agentEvalScope->hasApplicationValue('emptyValue'));
        self::assertFalse($agentEvalScope->hasApplicationValue('absent'));
        self::assertNull($agentEvalScope->getApplicationValue('absent'));
        self::assertSame(['externalKey' => 4100, 'emptyValue' => null], $agentEvalScope->getApplicationValues());
    }

    public function testAnAgentSpaceWithoutARowGivesNoIdUntilItIsRecordedAsStored(): void
    {
        $agentSpaceWithoutRow = $this->buildAgentSpace(null);
        $agentEvalScope = AgentEvalScope::forAgentSpace($agentSpaceWithoutRow, $this->buildAccount(5));

        self::assertNull($agentEvalScope->getAgentSpaceId());
        self::assertSame($agentSpaceWithoutRow, $agentEvalScope->getAgentSpace());
        self::assertNull($agentEvalScope->getStoredAgentSpace(), 'a row and a harness call name only a stored space');
        self::assertFalse($agentEvalScope->hasStoredAgentSpace());

        $storedAgentSpace = $this->buildAgentSpace(23);
        $agentEvalScope->recordStoredAgentSpace($storedAgentSpace);
        $agentEvalScope->recordStoredAgentSpace($storedAgentSpace);

        self::assertSame(23, $agentEvalScope->getAgentSpaceId());
        self::assertSame($storedAgentSpace, $agentEvalScope->getStoredAgentSpace());
    }

    public function testAScopeWithoutAnAgentSpaceWorksAtAccountLevel(): void
    {
        $agentEvalScope = AgentEvalScope::forAgentSpace(null, $this->buildAccount(5));

        self::assertNull($agentEvalScope->getAgentSpace());
        self::assertNull($agentEvalScope->getAgentSpaceId());
        self::assertFalse($agentEvalScope->hasStoredAgentSpace());
    }

    public function testASpaceWithoutAnIdIsNotRecordedAsStored(): void
    {
        $agentEvalScope = AgentEvalScope::forAgentSpace(null, $this->buildAccount(5));

        $this->expectException(InternalErrorException::class);
        $agentEvalScope->recordStoredAgentSpace($this->buildAgentSpace(null));
    }

    public function testTheRunKeepsTheStoredAgentSpaceItWorksIn(): void
    {
        $agentEvalScope = AgentEvalScope::forAgentSpace($this->buildAgentSpace(17), $this->buildAccount(5));

        $this->expectException(InternalErrorException::class);
        $agentEvalScope->recordStoredAgentSpace($this->buildAgentSpace(18));
    }
}
