<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\UsageLogs\AIUsageEnvelopeContext;
use DDD\Domain\AI\Entities\UsageLogs\Enums\AIUsageInitiator;
use DDD\Domain\AI\Services\AIUsageEnvelopesService;
use RuntimeException;

/**
 * The usage envelopes as the session worker opens them: whether one is open already is scripted, every opening and
 * closing is kept, and an opening may be scripted to fail.
 */
class RecordingAIUsageEnvelopesService extends AIUsageEnvelopesService
{
    public const string OPENING_REFUSAL_MESSAGE = 'the envelope store is unreachable';

    public bool $envelopeIsOpenAlready = false;

    public bool $openingFails = false;

    /** @var array<int, array{accountId: int|null, scope: string, initiatedBy: string}> */
    public array $openedEnvelopes = [];

    /** @var array<int, AIUsageEnvelopeContext|null> */
    public array $closedEnvelopes = [];

    public function hasActiveEnvelope(): bool
    {
        return $this->envelopeIsOpenAlready;
    }

    public function openEnvelope(
        ?int $accountId,
        string $scope,
        string $initiatedBy = AIUsageInitiator::ACCOUNT
    ): AIUsageEnvelopeContext {
        if ($this->openingFails) {
            throw new RuntimeException(static::OPENING_REFUSAL_MESSAGE);
        }
        $this->openedEnvelopes[] = ['accountId' => $accountId, 'scope' => $scope, 'initiatedBy' => $initiatedBy];
        $envelopeContext = AIUsageEnvelopeContext::forScope($accountId, $scope, $initiatedBy);
        $envelopeContext->envelopeId = count($this->openedEnvelopes);

        return $envelopeContext;
    }

    public function closeEnvelope(?AIUsageEnvelopeContext $envelopeContext = null): void
    {
        $this->closedEnvelopes[] = $envelopeContext;
    }
}
