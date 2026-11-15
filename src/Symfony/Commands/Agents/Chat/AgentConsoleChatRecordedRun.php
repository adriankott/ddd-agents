<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSessionInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A recording or a replay of several console chat turns, as an application starts it: the session that records or
 * answers every call, the owner turns it drives (a replay takes them from the recording) and whether every turn takes
 * the worker path — or why the run was refused before it started.
 *
 * A plain object of one chat run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentConsoleChatRecordedRun
{
    /**
     * @param AgentRecordedRunSessionInterface|null $agentRecordedRunSession null when refused
     * @param string[] $turns the owner messages, one per turn
     * @param bool $async every turn takes the worker path
     * @param string|null $refusalText why the run was refused (an unreadable recording, a replay under other settings)
     */
    public function __construct(
        public readonly ?AgentRecordedRunSessionInterface $agentRecordedRunSession = null,
        public readonly array $turns = [],
        public readonly bool $async = false,
        public readonly ?string $refusalText = null,
    ) {
    }

    /**
     * @param AgentRecordedRunSessionInterface $agentRecordedRunSession a session with its scope set, not started
     * @param string[] $turns
     * @param bool $async
     * @return static
     */
    public static function forSession(AgentRecordedRunSessionInterface $agentRecordedRunSession, array $turns, bool $async): static
    {
        return new static($agentRecordedRunSession, array_values($turns), $async);
    }

    /**
     * @param string $refusalText printed as an error; the command ends with `Command::INVALID`
     * @return static
     */
    public static function refused(string $refusalText): static
    {
        return new static(null, [], false, $refusalText);
    }

    /** @return bool */
    public function isRefused(): bool
    {
        return $this->agentRecordedRunSession === null;
    }
}
