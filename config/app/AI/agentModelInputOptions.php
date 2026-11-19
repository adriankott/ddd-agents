<?php

declare(strict_types=1);

/**
 * What the model input leaves out to save tokens on every call — the package default, read by
 * {@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInputOptions} as `Config::get('AI.agentModelInputOptions')`.
 * An application replaces this file whole with its own `config/app/AI/agentModelInputOptions.php` (a missing key keeps
 * the default); a purpose overrides single keys with its `modelInputOptions:` map. These defaults leave the model input
 * exactly as it is.
 *
 * `offersDelegationTools` (`delegate`, `check_delegations`, `message_sub_agent`, `wait_for_sub_agents`),
 * `offersTaskPlanTools` (`update_task_plan`, `set_task_status`, `get_task_plans`), `offersAskOwner`,
 * `offersFindWidgets`: the meta tool groups. `teachesWidgets`: the base prompt's `Widgets` section and the composed-view
 * kit (false also takes `findWidgets` away). `droppedBasePromptSections`: headings of base prompt sections left out
 * whole. `dropsMemorySectionWithoutMemoryTool`: leave out the memory section while no reachable tool writes agent
 * memory. `offersReachableToolsFromTheFirstCall`: false = under append-only loading the tools of a skill arrive with
 * its load, also below `appendOnlyReachableToolSetCeilingTokens`.
 */
return [
    'offersDelegationTools' => true,
    'offersTaskPlanTools' => true,
    'offersAskOwner' => true,
    'offersFindWidgets' => true,
    'teachesWidgets' => true,
    'droppedBasePromptSections' => [],
    'dropsMemorySectionWithoutMemoryTool' => false,
    'offersReachableToolsFromTheFirstCall' => true,
];
