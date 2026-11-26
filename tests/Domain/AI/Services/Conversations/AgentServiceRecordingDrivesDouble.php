<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationAgentService;
use DDD\Domain\Common\Entities\Accounts\Account;
use ReflectionMethod;

/**
 * An agent service that starts nothing: it records what a conversation was to be driven with and answers a new one.
 * It takes the arguments of `driveConversation()` as they come (positional or named) and names each one after the
 * parameter of the parent method, so a new parameter there needs no change here.
 */
class AgentServiceRecordingDrivesDouble extends AIConversationAgentService
{
    public const int DRIVEN_CONVERSATION_ID = 881234;

    /** @var array<int, array<string, mixed>> One entry per drive: the arguments the caller chose */
    public array $recordedDrives = [];

    public function driveConversation(mixed ...$driveArguments): AIConversation
    {
        $driveArgumentsByName = $this->nameDriveArguments($driveArguments);
        $agentSpace = $driveArgumentsByName['agentSpace'] ?? null;
        $runAsAccount = $driveArgumentsByName['runAsAccount'] ?? null;
        $this->recordedDrives[] = [
            'scope' => $driveArgumentsByName['scope'] ?? null,
            'conversationId' => $driveArgumentsByName['aiConversationId'] ?? null,
            'agentSpaceId' => $agentSpace instanceof AgentSpace ? (int)$agentSpace->id : null,
            'message' => $driveArgumentsByName['message'] ?? null,
            'async' => $driveArgumentsByName['async'] ?? false,
            'initiatorType' => $driveArgumentsByName['initiatorType'] ?? null,
            'scheduleId' => $driveArgumentsByName['scheduleId'] ?? null,
            'runAsAccountId' => $runAsAccount instanceof Account ? (int)$runAsAccount->id : null,
            'purposeName' => $driveArgumentsByName['purposeName'] ?? null,
            'reportChannel' => $driveArgumentsByName['reportChannel'] ?? null,
            'aiModelName' => $driveArgumentsByName['aiModelName'] ?? null,
        ];
        $drivenConversation = new AIConversation();
        $drivenConversation->id = static::DRIVEN_CONVERSATION_ID;
        return $drivenConversation;
    }

    /**
     * The arguments keyed by the parameter names of the parent's `driveConversation()`: a positional argument gets the
     * name of its position, a named one keeps its name.
     *
     * @param array<int|string, mixed> $driveArguments
     * @return array<string, mixed>
     */
    protected function nameDriveArguments(array $driveArguments): array
    {
        $parameterNames = [];
        foreach ((new ReflectionMethod(AIConversationAgentService::class, 'driveConversation'))->getParameters() as $parameter) {
            $parameterNames[$parameter->getPosition()] = $parameter->getName();
        }
        $driveArgumentsByName = [];
        foreach ($driveArguments as $argumentKey => $argumentValue) {
            $driveArgumentsByName[is_int($argumentKey) ? $parameterNames[$argumentKey] : $argumentKey] = $argumentValue;
        }
        return $driveArgumentsByName;
    }
}
