<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\Agent\AgentClientContextService;

/** An application's rendering: its own key labels and the name of the record on screen. */
class AgentClientContextServiceWithRecordNamesDouble extends AgentClientContextService
{
    /** @var string[] record name by `<kind>:<id>` */
    public array $recordNamesByKindAndId = [];

    public function getClientContextKeyLabel(string $key): string
    {
        return $key === self::KEY_RECORD ? 'open record' : parent::getClientContextKeyLabel($key);
    }

    public function describeRecordOfClientContext(AIConversation $conversation, string $recordKind, string $recordId): ?string
    {
        $recordName = $this->recordNamesByKindAndId[$recordKind . ':' . $recordId] ?? null;
        return $recordName !== null ? $recordKind . ' "' . $recordName . '" (' . $recordId . ')' : null;
    }
}
