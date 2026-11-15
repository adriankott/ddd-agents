<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Quality;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * Which root conversations a scorecard run reads
 * ({@see \DDD\Domain\AI\Services\Conversations\Quality\AgentConversationScorecardsService::findConversationIdsForSelection()}):
 * the root conversations created on or after {@see self::$createdSince}, optionally of some purposes, without the eval
 * runs and the internal accounts unless they are included, at most {@see self::$limit}, oldest first.
 *
 * @phpstan-consistent-constructor
 */
class AgentConversationScorecardSelection extends ValueObject
{
    /** @var string The earliest creation moment, `Y-m-d` or `Y-m-d H:i:s` */
    public string $createdSince = '';

    /** @var bool Whether the selection is restricted by purpose at all; false = every purpose and the regular chats */
    public bool $isRestrictedByPurpose = false;

    /** @var string[] The purpose names selected when {@see self::$isRestrictedByPurpose} */
    public array $purposeNames = [];

    /** @var bool Whether the conversations without a purpose (the regular chats) are selected when {@see self::$isRestrictedByPurpose} */
    public bool $includesConversationsWithoutPurpose = false;

    /** @var bool Whether the eval runs (`runSettings.evalRun` = true) are selected */
    public bool $includesEvalRuns = false;

    /** @var bool Whether the conversations of the accounts the application counts as internal are selected */
    public bool $includesInternalAccounts = false;

    /** @var int The most conversations selected; at least one */
    public int $limit = 1;

    /**
     * Every root conversation created on or after `$createdSince`, eval runs and internal accounts left out.
     *
     * @param string $createdSince `Y-m-d` or `Y-m-d H:i:s`
     * @param int $limit the most conversations selected (at least one is selected)
     * @return static
     */
    public static function createdSince(string $createdSince, int $limit): static
    {
        $agentConversationScorecardSelection = new static();
        $agentConversationScorecardSelection->createdSince = $createdSince;
        $agentConversationScorecardSelection->limit = max(1, $limit);
        return $agentConversationScorecardSelection;
    }

    /**
     * Restricts the selection to the purposes `$purposeNames` and, when `$includesConversationsWithoutPurpose`, the
     * conversations without a purpose. Both empty restricts nothing.
     *
     * @param string[] $purposeNames
     * @param bool $includesConversationsWithoutPurpose
     * @return void
     */
    public function restrictToPurposes(array $purposeNames, bool $includesConversationsWithoutPurpose): void
    {
        $this->purposeNames = array_values(array_unique($purposeNames));
        $this->includesConversationsWithoutPurpose = $includesConversationsWithoutPurpose;
        $this->isRestrictedByPurpose = $this->purposeNames !== [] || $includesConversationsWithoutPurpose;
    }
}
