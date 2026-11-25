<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Admission;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;

/** The usage envelopes of ddd-ai without a database: whether one is open is {@see $envelopeIsOpen}; opens and closes are recorded. */
class RecordingAIUsageEnvelopesService extends AIUsageEnvelopesService
{
    public bool $envelopeIsOpen = false;

    /** @var string[] "open:<accountId>:<scope>" and "close:<scope>", in order */
    public array $recordedActions = [];

    public function hasActiveEnvelope(): bool
    {
        return $this->envelopeIsOpen;
    }

    public function openEnvelope(?int $accountId, string $scope, string $initiatedBy = AIUsageInitiator::ACCOUNT): AIUsageEnvelopeContext
    {
        $this->recordedActions[] = 'open:' . $accountId . ':' . $scope;

        return AIUsageEnvelopeContext::forScope($accountId, $scope, $initiatedBy);
    }

    public function closeEnvelope(?AIUsageEnvelopeContext $envelopeContext = null): void
    {
        $this->recordedActions[] = 'close:' . $envelopeContext?->scope;
    }
}
