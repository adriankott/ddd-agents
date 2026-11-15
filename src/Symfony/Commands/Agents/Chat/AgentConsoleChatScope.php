<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\Common\Entities\Accounts\Account;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Whom the console chat talks as and where: the account the run authenticates and the space a new conversation is
 * created in (null = a conversation at account level, or a space the application places itself) — or why the options
 * name no such account, with the exit code of the refusal.
 *
 * A plain object of one chat run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentConsoleChatScope
{
    /**
     * @param Account|null $account the account of the run; null when refused
     * @param AgentSpace|null $agentSpace the space new conversations are created in
     * @param string|null $refusalText why the options name no account; null when an account was found
     * @param int $refusalExitCode the exit code of the refusal
     */
    public function __construct(
        public readonly ?Account $account = null,
        public readonly ?AgentSpace $agentSpace = null,
        public readonly ?string $refusalText = null,
        public readonly int $refusalExitCode = Command::FAILURE,
    ) {
    }

    /**
     * @param Account $account
     * @param AgentSpace|null $agentSpace
     * @return static
     */
    public static function forAccountInAgentSpace(Account $account, ?AgentSpace $agentSpace): static
    {
        return new static($account, $agentSpace);
    }

    /**
     * @param string $refusalText printed as an error
     * @param int $refusalExitCode `Command::INVALID` for options that do not name a scope, `Command::FAILURE` for a
     *                             named scope that was not found
     * @return static
     */
    public static function refused(string $refusalText, int $refusalExitCode): static
    {
        return new static(null, null, $refusalText, $refusalExitCode);
    }

    /** @return bool whether the options name no usable account */
    public function isRefused(): bool
    {
        return $this->account === null;
    }
}
