<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\SeedAccounts;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the clean start of one seed account removed before an evaluation case
 * ({@see \DDD\Domain\AI\Services\Eval\AgentEvalSeedAccountResetService::resetAccountForCleanStart()}): the wait for the
 * account's runs in flight and the purge of its earlier conversations. An application that removes more of the
 * account's state adds its fields on its subclass of this class and their lines in {@see buildLinesBeforePurge()} and
 * {@see buildLinesAfterPurge()}, which surround the purge lines in that order.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalSeedAccountResetReport extends ValueObject
{
    public int $accountId = 0;

    /** @var int Seconds the clean start waited for the account's runs in flight. */
    public int $waitedSecondsForRunsInFlight = 0;

    /** @var int Runs still in flight when the wait ended (their trees are kept). */
    public int $runsStillInFlightAfterWait = 0;

    /** @var int Conversation trees of the account deleted with every dependent row. */
    public int $purgedConversationTrees = 0;

    /** @var int Rows the conversation purge deleted or unlinked, over every dependant table. */
    public int $purgedConversationRows = 0;

    /** @var int Trees kept because a run of them is in flight or wrote a fresh heartbeat. */
    public int $keptConversationTreesInFlight = 0;

    /** @var string|null Why the conversation purge stopped. */
    public ?string $conversationPurgeStoppedBecause = null;

    /**
     * @return string[] the progress lines of the clean start, one per part that removed something, in this order: the
     *     wait, the application's lines before the purge, the purge, the application's lines after it; one line that
     *     the account was clean when nothing was removed
     */
    public function buildProgressLines(): array
    {
        $progressLines = array_merge($this->buildWaitLines(), $this->buildLinesBeforePurge(), $this->buildPurgeLines(), $this->buildLinesAfterPurge());
        return $progressLines === [] ? [$this->buildCleanLine()] : $progressLines;
    }

    /** @return string[] the line of the wait for the account's runs in flight, when there was one */
    public function buildWaitLines(): array
    {
        if ($this->waitedSecondsForRunsInFlight <= 0 && $this->runsStillInFlightAfterWait <= 0) {
            return [];
        }
        return ["waited {$this->waitedSecondsForRunsInFlight} s for the account's runs in flight"
            . ($this->runsStillInFlightAfterWait > 0 ? " — {$this->runsStillInFlightAfterWait} still running" : '')];
    }

    /** @return string[] the lines of what the application removed before the conversation purge; none by default */
    public function buildLinesBeforePurge(): array
    {
        return [];
    }

    /** @return string[] the lines of the conversation purge: the purged trees, the trees kept with a run in flight */
    public function buildPurgeLines(): array
    {
        $purgeLines = [];
        if ($this->purgedConversationTrees > 0) {
            $purgeLines[] = "purged {$this->purgedConversationTrees} earlier conversation tree(s) with {$this->purgedConversationRows} dependent row(s)";
        }
        if ($this->keptConversationTreesInFlight > 0) {
            $purgeLines[] = "⚠ kept {$this->keptConversationTreesInFlight} conversation tree(s) with a run in flight";
        }
        return $purgeLines;
    }

    /** @return string[] the lines of what the application removed after the conversation purge; none by default */
    public function buildLinesAfterPurge(): array
    {
        return [];
    }

    /** The one line of a clean start that removed nothing. */
    public function buildCleanLine(): string
    {
        return "seed account {$this->accountId} was clean";
    }
}
