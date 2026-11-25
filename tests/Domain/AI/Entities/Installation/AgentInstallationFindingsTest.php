<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Tests\Agents\Support\UnitTestCase;

/** Findings keep their order and duplicates; the counts tell blocking from warnings; an unknown severity is a warning. */
class AgentInstallationFindingsTest extends UnitTestCase
{
    public function testFindingsCountBlockingAndWarningsAndKeepDuplicates(): void
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $agentInstallationFindings->addFinding('missingTables', AgentInstallationFinding::SEVERITY_BLOCKING, 'agent_runs', 'missing');
        $agentInstallationFindings->addFinding('skillLint', AgentInstallationFinding::SEVERITY_WARNING, 'Demo.Skill', 'name');
        $agentInstallationFindings->addFinding('skillLint', AgentInstallationFinding::SEVERITY_WARNING, 'Demo.Skill', 'name');
        $agentInstallationFindings->addFinding('skillLint', 'INFO', 'Demo.Other', 'unknown severity');

        self::assertSame(4, $agentInstallationFindings->count());
        self::assertTrue($agentInstallationFindings->hasBlockingFindings());
        self::assertSame(1, $agentInstallationFindings->countBlockingFindings());
        self::assertSame(3, $agentInstallationFindings->countWarnings());
        self::assertSame(AgentInstallationFinding::SEVERITY_WARNING, $agentInstallationFindings->getElements()[3]->severity);
        self::assertSame(['Demo.Skill', 'Demo.Skill', 'Demo.Other'], $agentInstallationFindings->findFindingsOfCheck('skillLint')->getSubjects());
        self::assertFalse(AgentInstallationFindings::newInstance()->hasBlockingFindings());
    }
}
