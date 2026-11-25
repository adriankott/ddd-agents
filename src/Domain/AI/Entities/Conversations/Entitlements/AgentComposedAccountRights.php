<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Entitlements;

/**
 * The rights the package checks when an application returns its own rights object: the application's answers composed
 * with the package's mapping `AI.accountRights` ({@see AgentRoleMappedAccountRights}). Built by
 * {@see \DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService::composeAccountRights()}.
 *
 * Per right name:
 * - the application object implements {@see AgentAccountRightsAnsweringInterface} and answers the name → its answer
 *   is final (held or not);
 * - else the right is held when the application object holds it OR the mapping holds it: by a role, by `'*'`, or by
 *   a delegation `['right' => '<application right name>']`, which asks the application object for that right.
 *
 * So an application object that knows only its own right names leaves the package rights (`scheduleAgentTasks`,
 * `useWeb`, …) to the mapping — before this composition every one of them was "not held". A right the application
 * wants to withhold is left out of its own `config/app/AI/accountRights.php` (which replaces the package's file whole)
 * or answered through the answering interface.
 *
 * @phpstan-consistent-constructor
 */
class AgentComposedAccountRights implements AgentAccountRightsInterface
{
    public function __construct(
        protected AgentAccountRightsInterface $applicationAccountRights,
        protected AgentRoleMappedAccountRights $agentRoleMappedAccountRights
    ) {
    }

    /** The application's rights object, composed with the mapping */
    public static function compose(
        AgentAccountRightsInterface $applicationAccountRights,
        AgentRoleMappedAccountRights $agentRoleMappedAccountRights
    ): static {
        return new static($applicationAccountRights, $agentRoleMappedAccountRights);
    }

    public function getMissingRightNames(array $rightNames): array
    {
        $missingRightNames = [];
        foreach ($rightNames as $rightName) {
            if (!$this->holdsRight((string)$rightName)) {
                $missingRightNames[] = (string)$rightName;
            }
        }
        return $missingRightNames;
    }

    /** Whether the composition holds `$rightName` (see the class description for the order) */
    public function holdsRight(string $rightName): bool
    {
        if ($this->isAnsweredByApplication($rightName)) {
            return $this->applicationAccountRights->getMissingRightNames([$rightName]) === [];
        }
        if ($this->applicationAccountRights->getMissingRightNames([$rightName]) === []) {
            return true;
        }
        return $this->agentRoleMappedAccountRights->holdsRight($rightName, $this->applicationAccountRights);
    }

    /** Whether the application's object decides `$rightName` alone ({@see AgentAccountRightsAnsweringInterface}) */
    public function isAnsweredByApplication(string $rightName): bool
    {
        return $this->applicationAccountRights instanceof AgentAccountRightsAnsweringInterface
            && $this->applicationAccountRights->answersRightName($rightName);
    }

    public function getApplicationAccountRights(): AgentAccountRightsInterface
    {
        return $this->applicationAccountRights;
    }

    public function getRoleMappedAccountRights(): AgentRoleMappedAccountRights
    {
        return $this->agentRoleMappedAccountRights;
    }
}
