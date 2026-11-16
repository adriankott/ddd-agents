<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * The MCP packages a case's harness calls (its preparation, capture, restore and state-check calls) may reach: the
 * packages of the skills the case expects and of their sub-skills. A harness call is a deterministic tool call, not a
 * routing assertion, so a tool that lives in a sub-skill of the expected skill must be reachable for it too.
 */
class AgentEvalCasePackagesService extends Service
{
    /**
     * The package classes of the skills `expect.skills` and `expect.skill` name and of the direct sub-skills of each
     * (a sub-skill's own sub-skills are not followed), each class once, in the order the skills are listed.
     *
     * @param AgentEvalCase $agentEvalCase
     * @return string[]
     */
    public function findPackageClassesOfCase(AgentEvalCase $agentEvalCase): array
    {
        $skillNames = $this->findExpectedSkillNamesWithSubSkills($agentEvalCase);
        $packageClasses = [];
        foreach ($skillNames as $skillName) {
            foreach (AISkill::getPackagesForSkill($skillName) as $packageClass) {
                $packageClasses[] = $packageClass;
            }
        }
        return array_values(array_unique($packageClasses));
    }

    /**
     * The expected skills (`expect.skills`, then `expect.skill`) followed by the direct sub-skills of each, each name
     * once.
     *
     * @param AgentEvalCase $agentEvalCase
     * @return string[]
     */
    public function findExpectedSkillNamesWithSubSkills(AgentEvalCase $agentEvalCase): array
    {
        $expect = $agentEvalCase->getExpect();
        $expectedSkillNames = is_array($expect['skills'] ?? null) ? array_map('strval', $expect['skills']) : [];
        if (isset($expect['skill'])) {
            $expectedSkillNames[] = (string)$expect['skill'];
        }
        $aISkillsService = $this->getAISkillsService();
        $skillNames = $expectedSkillNames;
        foreach ($expectedSkillNames as $expectedSkillName) {
            foreach ($aISkillsService->getAISkillByName($expectedSkillName)->subSkillNames ?? [] as $subSkillName) {
                $skillNames[] = (string)$subSkillName;
            }
        }
        return array_values(array_unique($skillNames));
    }

    /**
     * The skills service (the application's class, when it replaces it).
     *
     * @return AISkillsService
     */
    protected function getAISkillsService(): AISkillsService
    {
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        return $aISkillsService;
    }
}
