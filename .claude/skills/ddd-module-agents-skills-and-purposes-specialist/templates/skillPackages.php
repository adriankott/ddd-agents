<?php
// placeholder {{SKILL_NAME}} sample: Demo.Notes
// placeholder {{PACKAGE_FQCN}} sample: AgentsReference\Presentation\Mcp\Demo\DemoNotesMCPPackage

declare(strict_types=1);

use DDD\Domain\AI\Entities\Skills\AISkill;

/**
 * The host's skill → MCP package binding (config `AI.skillPackages`). It REPLACES the package's file whole: list every
 * skill the host offers, the package's own three included. A package is named by class or by its package id
 * (`MCPPackage::$name`); an id resolves only for a package the source-root scan finds (the application's src/ under
 * the root namespace `App\` and the modules); a host with another root namespace binds its own packages by class.
 */
return [
    AISkill::ASSISTANT_MANDATES => ['agent.mandates'],
    AISkill::ASSISTANT_TASKS => ['agent.taskSchedules'],
    AISkill::ASSISTANT_TASK_RUN_REPORTS => ['agent.taskRunReports'],
    AISkill::MEMORY_MANAGEMENT => ['agent.memories'], // offered only while AI.agentMemory.enabled is on
    '{{SKILL_NAME}}' => [\{{PACKAGE_FQCN}}::class],
];
