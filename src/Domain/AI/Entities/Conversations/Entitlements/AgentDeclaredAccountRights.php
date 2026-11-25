<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The account rights the installed packages declare, one entry per right name
 * ({@see \DDD\Domain\AI\Services\Conversations\Entitlements\AgentDeclaredAccountRightsService}).
 *
 * @property AgentDeclaredAccountRight[] $elements
 * @method AgentDeclaredAccountRight[] getElements()
 * @method AgentDeclaredAccountRight|null getByUniqueKey(string $uniqueKey)
 */
class AgentDeclaredAccountRights extends ObjectSet
{
    /** Records that `$declaringClassName` declares `$rightName` (one entry per name) */
    public function addDeclaration(string $rightName, string $declaringClassName): void
    {
        $rightName = trim($rightName);
        if ($rightName === '') {
            return;
        }
        $agentDeclaredAccountRight = $this->findByRightName($rightName);
        if ($agentDeclaredAccountRight !== null) {
            $agentDeclaredAccountRight->addDeclaringClassName($declaringClassName);
            return;
        }
        $newDeclaredAccountRight = AgentDeclaredAccountRight::forRightName($rightName, $declaringClassName);
        $this->add($newDeclaredAccountRight);
    }

    public function findByRightName(string $rightName): ?AgentDeclaredAccountRight
    {
        return $this->getByUniqueKey(AgentDeclaredAccountRight::uniqueKeyStatic($rightName));
    }

    /**
     * Every declared right name, sorted.
     *
     * @return string[]
     */
    public function getRightNames(): array
    {
        $rightNames = [];
        foreach ($this->getElements() as $agentDeclaredAccountRight) {
            $rightNames[] = $agentDeclaredAccountRight->rightName;
        }
        sort($rightNames);
        return $rightNames;
    }
}
