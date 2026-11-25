<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

/**
 * Optional beside {@see AgentAccountRightsInterface}: the rights object of an application says which right names it
 * answers itself. For such a name its answer is final, also a "not held" — the package's mapping `AI.accountRights`
 * is not asked ({@see AgentComposedAccountRights}). A rights object without this interface answers the names it holds;
 * a name it does not hold may still be held through the mapping.
 */
interface AgentAccountRightsAnsweringInterface
{
    /**
     * Whether the application decides `$rightName` itself (held or not), so the package's mapping is not asked.
     *
     * @param string $rightName
     * @return bool
     */
    public function answersRightName(string $rightName): bool;
}
