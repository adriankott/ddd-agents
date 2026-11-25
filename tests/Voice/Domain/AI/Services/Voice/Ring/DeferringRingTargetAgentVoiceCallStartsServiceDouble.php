<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Ring;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * An application's call start with a further ring target, a target that rings several phones, written the way such an
 * application overrides the new points: its reachability question answers per target from
 * {@see $reachabilityByRingTargetName} (a target without an entry: the package's answer), its ring of a further target
 * answers from {@see $deliveryByRingTargetName} and its device counts from {@see $selectedDeviceCountsByRingTargetName}
 * and {@see $rungDeviceCountsByRingTargetName}. Every question and every ring is logged in {@see $steps} in the order
 * it happened, with the agent space the question was asked for.
 */
class DeferringRingTargetAgentVoiceCallStartsServiceDouble extends AgentVoiceCallStartsService
{
    public const string PHONES_RING_TARGET = 'phones';

    public const string PAGER_RING_TARGET = 'pager';

    /** @var string[] */
    public array $ringTargetNames = [self::RING_TARGET_OPEN_CLIENTS, self::PHONES_RING_TARGET];

    /** @var array<string, string> One of the RING_TARGET_* answers per target */
    public array $reachabilityByRingTargetName = [];

    /** @var array<string, bool> Whether the ring of a further target reached somebody */
    public array $deliveryByRingTargetName = [];

    /** @var array<string, int> */
    public array $selectedDeviceCountsByRingTargetName = [];

    /** @var array<string, int> */
    public array $rungDeviceCountsByRingTargetName = [];

    /** @var string[] `asked <target> for space <id|none>`, `rang <target>` in the order they happened */
    public array $steps = [];

    /** @return string[] */
    public function callFindRingTargetNamesToRing(Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $ringConversation): array
    {
        return $this->findRingTargetNamesToRing($ownerAccount, $agentSpace, $ringConversation);
    }

    public function callFindRingTargetReachability(string $ringTargetName, Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $ringConversation): string
    {
        return $this->findRingTargetReachability($ringTargetName, $ownerAccount, $agentSpace, $ringConversation);
    }

    /** @return string[] */
    protected function getRingTargetNames(): array
    {
        return $this->ringTargetNames;
    }

    protected function findRingTargetReachability(string $ringTargetName, Account $ownerAccount, ?AgentSpace $agentSpace, ?AIConversation $ringConversation): string
    {
        $this->steps[] = 'asked ' . $ringTargetName . ' for space ' . ($agentSpace !== null ? $agentSpace->id : 'none');
        return $this->reachabilityByRingTargetName[$ringTargetName]
            ?? parent::findRingTargetReachability($ringTargetName, $ownerAccount, $agentSpace, $ringConversation);
    }

    protected function ringCallOnTarget(string $ringTargetName, AgentVoiceCall $agentVoiceCall, ?AgentSpace $agentSpace): bool
    {
        $this->steps[] = 'rang ' . $ringTargetName;
        if (array_key_exists($ringTargetName, $this->deliveryByRingTargetName)) {
            return $this->deliveryByRingTargetName[$ringTargetName];
        }
        return parent::ringCallOnTarget($ringTargetName, $agentVoiceCall, $agentSpace);
    }

    protected function findSelectedDeviceCountOfRingTarget(string $ringTargetName, AgentVoiceCall $agentVoiceCall): ?int
    {
        return $this->selectedDeviceCountsByRingTargetName[$ringTargetName] ?? null;
    }

    protected function findRungDeviceCountOfRingTarget(string $ringTargetName, AgentVoiceCall $agentVoiceCall): ?int
    {
        return $this->rungDeviceCountsByRingTargetName[$ringTargetName] ?? null;
    }
}
