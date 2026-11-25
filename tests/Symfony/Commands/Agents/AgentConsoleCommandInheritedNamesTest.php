<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Symfony\Commands\Agents\AgentReapStuckConversationsCommand;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A command built by hand: a subclass without an `#[AsCommand]` of its own is named like the package command it
 * replaces (name, aliases, description); a subclass with its own attribute and a name passed in keep theirs.
 */
class AgentConsoleCommandInheritedNamesTest extends UnitTestCase
{
    public function testSubclassWithoutOwnAttributeTakesNameAliasesAndDescriptionOfThePackageCommand(): void
    {
        $packageCommand = new AgentReapStuckConversationsCommand();
        $replacementCommand = new ReapCommandReplacementWithoutOwnAttribute();

        $this->assertSame('app:agent:reap', $replacementCommand->getName());
        $this->assertSame($packageCommand->getAliases(), $replacementCommand->getAliases());
        $this->assertContains('app:ai-agent:reap', $replacementCommand->getAliases());
        $this->assertSame($packageCommand->getDescription(), $replacementCommand->getDescription());
        $this->assertNotSame('', $replacementCommand->getDescription());
    }

    public function testSubclassWithOwnAttributeKeepsItsOwnName(): void
    {
        $replacementCommand = new ReapCommandReplacementWithOwnAttribute();

        $this->assertSame('host:agent:reap', $replacementCommand->getName());
        $this->assertSame([], $replacementCommand->getAliases());
        $this->assertSame('The host\'s own reaper name', $replacementCommand->getDescription());
        $this->assertNull(ReapCommandReplacementWithOwnAttribute::findAsCommandAttributeOfNearestParentClass());
    }

    public function testNamePassedInWins(): void
    {
        $replacementCommand = new ReapCommandReplacementWithoutOwnAttribute('host:reap-now');

        $this->assertSame('host:reap-now', $replacementCommand->getName());
    }
}
