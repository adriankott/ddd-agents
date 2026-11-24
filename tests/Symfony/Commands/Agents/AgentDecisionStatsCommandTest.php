<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentDecisionStatsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The decision statistics keep the name and the seven options the operators call them with, run on the base of the
 * module's commands and work as the configured default account for console work.
 */
class AgentDecisionStatsCommandTest extends UnitTestCase
{
    public function testTheCommandKeepsItsNameAndOptions(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentDecisionStatsCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:agent:decision-stats', explode('|', $asCommandAttributes[0]->newInstance()->name)[0], 'the old name stays an alias');

        $agentDecisionStatsCommand = new AgentDecisionStatsCommand();
        self::assertInstanceOf(AgentConsoleCommand::class, $agentDecisionStatsCommand);
        $commandDefinition = $agentDecisionStatsCommand->getDefinition();
        self::assertSame(['site', 'from', 'to', 'cutoff', 'mode', 'power', 'includeEval'], array_keys($commandDefinition->getOptions()));
        self::assertSame(AgentDecisionModelSettings::SITE_SKILL_PRELOAD, $commandDefinition->getOption('site')->getDefault());
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} 00:00:00$/', (string)$commandDefinition->getOption('from')->getDefault());

        $usesDefaultAccount = new ReflectionMethod(AgentDecisionStatsCommand::class, 'usesDefaultAccountForConsoleWork');
        self::assertTrue($usesDefaultAccount->invoke($agentDecisionStatsCommand));
    }
}
