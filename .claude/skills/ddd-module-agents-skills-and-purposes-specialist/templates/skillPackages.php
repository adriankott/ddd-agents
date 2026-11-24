<?php
// placeholder {{SKILL_NAME}} sample: Notes.Entries
// placeholder {{PACKAGE_FQCN}} sample: App\Presentation\Mcp\Notes\NotesEntriesMCPPackage

declare(strict_types=1);

use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Presentation\Mcp\Client\Agents\AgentImagesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentMandatesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentMemoriesMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskRunReportsMCPPackage;
use DDD\Presentation\Mcp\Client\Agents\AgentTaskSchedulesMCPPackage;

/**
 * The host's skill → MCP package binding (config `AI.skillPackages`). It REPLACES the package's file whole: list every
 * skill the host offers, the package's own included. Packages are bound by class; the id (`MCPPackage::$name`,
 * `<provider>.<topic>.<feature>`) stays the public name in call rows and logs. The lint refuses an id in the binding.
 */
return [
    AISkill::AGENT_MANDATES => [AgentMandatesMCPPackage::class],
    AISkill::AGENT_TASKS => [AgentTaskSchedulesMCPPackage::class],
    AISkill::AGENT_TASK_RUN_REPORTS => [AgentTaskRunReportsMCPPackage::class],
    AISkill::AGENT_MEMORY => [AgentMemoriesMCPPackage::class], // offered only while AI.agentMemory.enabled is on
    AISkill::AGENT_IMAGES => [AgentImagesMCPPackage::class], // offered only while the picture store and its retention are on
    '{{SKILL_NAME}}' => [{{PACKAGE_FQCN}}::class],
];
