<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\Conversations\Schedules\AIConversationSchedule;
use DDD\Domain\AI\Services\Conversations\AIConversationSchedulesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use RuntimeException;

/**
 * The schedules service for the end of a run: the schedule of the run is given by the test, the verdict on its done
 * criteria is scripted, and the notice to the owner is recorded instead of written into a conversation.
 */
class SchedulesServiceWithScriptedRunTerminalDouble extends AIConversationSchedulesService
{
    /** @var AIConversationSchedule|null The schedule the lookup answers; null: it no longer exists */
    public ?AIConversationSchedule $fixtureSchedule = null;

    /** @var bool The lookup of the schedule throws */
    public bool $fixtureLookupThrows = false;

    /** @var bool|null The verdict on the done criteria; null: the judgment call failed */
    public ?bool $fixtureVerdict = null;

    /** @var bool The notice to the owner throws */
    public bool $fixtureNoticeThrows = false;

    /** @var string[] The lookups in order: the id, and whether the entity registry cache was allowed */
    public array $recordedLookups = [];

    /** @var bool|null Whether the entity rights applied to the lookup */
    public ?bool $rightsAppliedOnLookup = null;

    /** @var string[] The notices to the owner in order: `completed` or `failed` */
    public array $recordedNotices = [];

    /** @var string[] The run outcome texts handed to the judgment, in order */
    public array $judgedRunOutcomeTexts = [];

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        $this->recordedLookups[] = $entityId . ($useEntityRegistrCache ? ', cache allowed' : ', read fresh');
        $this->rightsAppliedOnLookup = DatabaseRepoEntity::getApplyRightsRestrictions();
        if ($this->fixtureLookupThrows) {
            throw new RuntimeException('The database is unavailable.');
        }
        return $this->fixtureSchedule;
    }

    protected function appendRunTerminalNoticeToCreatingConversation(AIConversationSchedule $schedule, bool $runCompleted): void
    {
        $this->recordedNotices[] = $runCompleted ? 'completed' : 'failed';
        if ($this->fixtureNoticeThrows) {
            throw new RuntimeException('The conversation of the owner is unavailable.');
        }
    }

    protected function judgeDoneCriteriaFulfilled(AIConversationSchedule $schedule, string $boundedRunOutcomeText): ?bool
    {
        $this->judgedRunOutcomeTexts[] = $boundedRunOutcomeText;
        return $this->fixtureVerdict;
    }
}
