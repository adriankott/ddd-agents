<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Result of `getVoiceCalls`: the calls (the history, or only the ones ringing or running now) and the conversation the
 * agent answers in, so that the call of THIS conversation carries its marker. Renders through the call set
 * ({@see AgentVoiceCalls::buildLlmMarkdown()}); this container only covers the empty case.
 */
class AgentVoiceCallsMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    /**
     * @var AgentVoiceCalls|null The account's calls with the agent, newest first: id, medium, direction, state,
     * answered time and duration, who started it, end reason, the linked conversation. Null = none.
     */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentVoiceCalls $voiceCalls = null;

    /** @var int|null The conversation the agent answers in: its call is marked "THIS conversation's call" */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $currentConversationId = null;

    /** @var bool Whether only the calls ringing or running right now were read */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public bool $onlyRunning = false;

    public function toLlmMarkdown(): string
    {
        if ($this->voiceCalls === null || $this->voiceCalls->count() === 0) {
            return $this->onlyRunning
                ? 'No call with ' . static::getAgentLabel() . ' is ringing or running right now.'
                : 'No calls with ' . static::getAgentLabel() . ' yet.';
        }
        return $this->voiceCalls->buildLlmMarkdown(time(), $this->currentConversationId, $this->onlyRunning);
    }

    /** What the agent is called in these texts: the persona's agent name, else {@see AgentVoiceCall::DEFAULT_AGENT_LABEL}. */
    protected static function getAgentLabel(): string
    {
        $agentName = AgentVoiceConfiguration::current()->agentName;
        return $agentName !== '' ? $agentName : AgentVoiceCall::DEFAULT_AGENT_LABEL;
    }
}
