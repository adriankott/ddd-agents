<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Memories;

use DDD\Domain\AI\Entities\Conversations\Memories\Curation\AgentMemoryCurationResult;
use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryCurationCall;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryCurationModelCallsService;
use ReflectionProperty;

/**
 * The model calls of the curation without a model: every load takes the next scripted answer and leaves on the call
 * what the language-model trait leaves after a real load (the request payload with its body and its headers, the raw
 * answer object, the parsed result, the tokens, the model). The headers carry {@see EGRESS_KEY}, so a test can prove
 * the record never copies them.
 */
class AgentMemoryCurationModelCallsServiceDouble extends AgentMemoryCurationModelCallsService
{
    /** @var string The egress authentication the scripted request headers carry */
    public const string EGRESS_KEY = 'test-egress-key-never-recorded';

    /** @var string The model every scripted call ran on */
    public const string MODEL_NAME = 'test/curator-model';

    /** @var array The answers in load order: a text, or null for no answer */
    public array $scriptedAnswers = [];

    /** @var string[] The stage of every load, in load order */
    public array $loadedStages = [];

    protected function loadCall(ArgusAgentMemoryCurationCall $argusAgentMemoryCurationCall): void
    {
        $loadIndex = count($this->loadedStages);
        $stage = (string)$argusAgentMemoryCurationCall->getCurationStage();
        $this->loadedStages[] = $stage;
        $answer = $this->scriptedAnswers[$loadIndex] ?? null;

        $argusAgentMemoryCurationCall->lastRequestPayload = [
            'body' => [
                'model' => self::MODEL_NAME,
                'messages' => [['role' => 'user', 'content' => $stage . ' request ' . $loadIndex]],
            ],
            'headers' => ['Authorization' => 'Bearer ' . self::EGRESS_KEY],
        ];
        $argusAgentMemoryCurationCall->lastRawResponse = $answer === null
            ? null
            : (object)['choices' => [(object)['message' => (object)['content' => $answer]]]];
        $argusAgentMemoryCurationCall->result = $answer === null ? null : AgentMemoryCurationResult::fromModelJson($answer);

        $aiModel = new AIModel();
        $aiModel->name = self::MODEL_NAME;
        $this->setTraitProperty($argusAgentMemoryCurationCall, 'aiModel', $aiModel);
        $this->setTraitProperty($argusAgentMemoryCurationCall, 'lastInputTokens', 100 * ($loadIndex + 1));
        $this->setTraitProperty($argusAgentMemoryCurationCall, 'lastOutputTokens', 10 * ($loadIndex + 1));
    }

    protected function setTraitProperty(ArgusAgentMemoryCurationCall $argusAgentMemoryCurationCall, string $propertyName, mixed $value): void
    {
        $reflectionProperty = new ReflectionProperty($argusAgentMemoryCurationCall, $propertyName);
        $reflectionProperty->setValue($argusAgentMemoryCurationCall, $value);
    }
}
