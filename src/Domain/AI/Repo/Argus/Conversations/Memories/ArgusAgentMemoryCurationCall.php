<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Repo\Argus\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationModelCall;
use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Accounts\Account;

/**
 * What the two model calls of the memory curation share (extraction and reconciliation): the parsed answer, the
 * language every stored content is written in, and the target of the AI costs. The model call itself (the Argus and
 * language-model traits with their attributes) is declared by each subclass.
 *
 * @property object|null $lastRawResponse The provider's raw answer of the last call (from the language-model trait)
 * @property array|null $lastRequestPayload The request of the last call with its `body` and `headers` (from the language-model trait)
 * @method AIModel|null getAIModel()
 * @method int|null getLastInputTokens()
 * @method int|null getLastOutputTokens()
 */
class ArgusAgentMemoryCurationCall extends ValueObject
{
    /** @var AgentMemoryCurationResult|null The parsed answer; null until the call returned */
    public ?AgentMemoryCurationResult $result = null;

    /** @var string The language code every stored content is written in (the owner's interface language) */
    public string $memoryLanguageCode = 'en';

    /** @var Account|null The account the AI costs are billed to */
    public ?Account $budgetAccount = null;

    /** @var Entity|null The entity within the account the AI costs are billed to (the billing entity of the space) */
    public ?Entity $billingEntity = null;

    /**
     * The parsed answer, or an invalid empty result when the call returned none.
     *
     * @return AgentMemoryCurationResult
     */
    public function getResultOrInvalidEmptyResult(): AgentMemoryCurationResult
    {
        return $this->result ?? AgentMemoryCurationResult::fromModelJson(null);
    }

    /**
     * The stage of this call (an {@see AgentMemoryCurationModelCall} STAGE_* constant); each subclass names its own.
     *
     * @return string|null
     */
    public function getCurationStage(): ?string
    {
        return null;
    }

    /**
     * The record of this call after its load, read from what the language-model trait kept of the last call: the
     * model, the request BODY (never the headers, which carry the egress authentication), the raw answer and the
     * tokens.
     *
     * @param int $attempt Which attempt of the call this load was (1, or 2 for the repetition)
     * @return AgentMemoryCurationModelCall
     */
    public function buildModelCallOfAttempt(int $attempt): AgentMemoryCurationModelCall
    {
        $agentMemoryCurationModelCall = new AgentMemoryCurationModelCall();
        $agentMemoryCurationModelCall->stage = $this->getCurationStage();
        $agentMemoryCurationModelCall->attempt = $attempt;
        $agentMemoryCurationModelCall->aiModelName = $this->getAIModel()?->name;
        $agentMemoryCurationModelCall->takeRequestBodyOfPayload($this->lastRequestPayload ?? null);
        $agentMemoryCurationModelCall->takeRawResponse($this->lastRawResponse ?? null);
        $agentMemoryCurationModelCall->rawResultText = $this->result?->rawResultText;
        $agentMemoryCurationModelCall->inputTokens = $this->getLastInputTokens();
        $agentMemoryCurationModelCall->outputTokens = $this->getLastOutputTokens();
        return $agentMemoryCurationModelCall;
    }
}
