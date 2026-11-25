<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\RunRelease;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\AIConversationRunState;
use DDD\Domain\AI\Services\Conversations\AIConversationRunClaimsService;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * The run claims of the release stage: the claim is always won with a fixed token, and every release is recorded with
 * its state, attempt count and handover marker. A release answers like the token-guarded UPDATE: false when another
 * worker holds the claim or the token was already released.
 */
class RunReleaseRunClaimsServiceDouble extends AIConversationRunClaimsService
{
    public const string RUN_TOKEN = 'release-stage-run-token';

    public RunReleaseEventLog $eventLog;

    /** @var bool Another worker holds the claim: every release of this run writes nothing. */
    public bool $claimHeldByAnotherWorker = false;

    /** @var string[] The run tokens a release already cleared. */
    public array $releasedRunTokens = [];

    /** @var int How often the claim was asked. */
    public int $claimCount = 0;

    public function __construct(RunReleaseEventLog $eventLog)
    {
        // The parent service is container-managed and holds no constructor state.
        $this->eventLog = $eventLog;
    }

    public function mintRunToken(): string
    {
        return self::RUN_TOKEN;
    }

    public function claimRun(AIConversation $conversation, string $runToken, int $hungAfterSeconds = self::RUN_HUNG_AFTER_SECONDS): bool
    {
        $this->claimCount++;
        $this->releasedRunTokens = array_values(array_diff($this->releasedRunTokens, [$runToken]));
        return true;
    }

    public function releaseRun(
        AIConversation $conversation,
        string $runToken,
        string $newRunState,
        ?int $resumeAttempts = null,
        ?DateTime $agentTurnOwedAt = null,
    ): bool {
        $this->eventLog->record(sprintf(
            'release %s attempts=%s owedAt=%s',
            $newRunState,
            $resumeAttempts === null ? 'unchanged' : (string)$resumeAttempts,
            RunReleaseEventLog::describeOwedAt($agentTurnOwedAt)
        ));
        if ($this->claimHeldByAnotherWorker || in_array($runToken, $this->releasedRunTokens, true)) {
            return false;
        }
        if ($newRunState !== AIConversationRunState::RUNNING) {
            $this->releasedRunTokens[] = $runToken;
        }
        return true;
    }

    public function getRunTokenClaimedInThisProcess(AIConversation $conversation): ?string
    {
        return self::RUN_TOKEN;
    }
}
