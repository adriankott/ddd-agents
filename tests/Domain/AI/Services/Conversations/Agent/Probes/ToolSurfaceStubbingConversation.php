<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\Probes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\LoadableSubSkillTools;
use DDD\Domain\AI\Entities\Conversations\Agent\OfferedSkillCatalog;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\Skills\AISkills;

/**
 * A conversation whose tree purpose and whose `load_tool` sub-skill tools are given: the purpose's blocked tools and the
 * tools of a sub-skill that is not loaded yet, without a purpose file or a skill tree.
 */
class ToolSurfaceStubbingConversation extends AIConversation
{
    /** The purpose of the conversation's tree; null = none */
    public ?AIConversationPurpose $stubbedPurposeOfConversationTree = null;

    /** The tools of sub-skills that are not loaded yet; null = read from the skill tree */
    public ?LoadableSubSkillTools $stubbedLoadableSubSkillTools = null;

    public function findPurposeOfConversationTree(?AgentRun $agentRun = null): ?AIConversationPurpose
    {
        return $this->stubbedPurposeOfConversationTree;
    }

    public function buildOfferedSkillCatalog(?AIConversationMessage $anchorMessage = null, array $assumeLoadedSkillNames = [], ?AgentRun $agentRun = null): OfferedSkillCatalog
    {
        if ($this->stubbedLoadableSubSkillTools === null) {
            return parent::buildOfferedSkillCatalog($anchorMessage, $assumeLoadedSkillNames, $agentRun);
        }
        /** @var OfferedSkillCatalog $offeredSkillCatalog */
        $offeredSkillCatalog = (new \ReflectionClass(OfferedSkillCatalog::class))->newInstanceWithoutConstructor();
        $offeredSkillCatalog->skills = new AISkills();
        $offeredSkillCatalog->alreadyLoadedSkillNames = [];
        return $offeredSkillCatalog;
    }

    public function buildLoadableSubSkillTools(OfferedSkillCatalog $offeredSkillCatalog, ?LoadedCapabilities $loadedCapabilities): LoadableSubSkillTools
    {
        return $this->stubbedLoadableSubSkillTools ?? parent::buildLoadableSubSkillTools($offeredSkillCatalog, $loadedCapabilities);
    }
}
