<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\AgentVoiceConfiguration;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * Result of `startVoiceCall`, small and flat on purpose (no LlmMarkdownRenderable: the JSON overhead is next to
 * nothing): which medium started, the call, the call conversation, the ring window, and what to tell the owner now. A
 * refusal is never a response: the tool throws its sentence ({@see self::buildRefusal()}).
 *
 * The base words the one medium of the package, the application's own window. Override points of an application's
 * subclass with a further medium: {@see self::forStartedCall()} (the answer of a call that started on it),
 * {@see self::buildRefusalOfFurtherReason()} (the sentences of its own refusal reasons), {@see self::buildRingingNote()}
 * and {@see self::buildOpenTheAppNextStep()} (what it adds to the two texts of the window). The tool package reaches
 * the subclass through `AgentVoiceCallsMCPPackage::buildStartedCallResponse()` and `buildStartRefusal()`.
 */
class AgentVoiceCallStartMcpResponseDto extends RestResponseDto
{
    /** @var string|null The medium the call started on: APP = the app is ringing on the owner's open clients */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $channel = null;

    /** @var int|null The id of the call */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $callId = null;

    /** @var int|null The conversation the call runs on: the transcript and the work of the call land there */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $conversationId = null;

    /** @var int|null How many seconds the app rings before the call ends unanswered */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $ringSeconds = null;

    /** @var string|null What to tell the owner now */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /** The answer of a call that started: the app is ringing. */
    public static function forStartedCall(AgentVoiceCallStart $agentVoiceCallStart): static
    {
        $responseDto = new static();
        $responseDto->channel = $agentVoiceCallStart->startedChannel;
        $responseDto->callId = $agentVoiceCallStart->agentVoiceCall?->id !== null ? (int)$agentVoiceCallStart->agentVoiceCall->id : null;
        $responseDto->conversationId = $agentVoiceCallStart->callConversationId;
        $responseDto->ringSeconds = AgentVoiceConfiguration::current()->ringSeconds;
        $responseDto->note = static::buildRingingNote($responseDto->ringSeconds);

        return $responseDto;
    }

    /** The sentence a refused call start answers the model with: what happened and the next step. */
    public static function buildRefusal(AgentVoiceCallStart $agentVoiceCallStart): string
    {
        $agentLabel = static::getAgentLabel();

        return match ($agentVoiceCallStart->refusalReason) {
            AgentVoiceCallStart::REFUSED_SCOPE_NOT_ACTIVE => 'No call: this account, or what ' . $agentLabel . ' works for in this conversation, is no longer active.',
            AgentVoiceCallStart::REFUSED_ALREADY_ON_A_CALL => 'No call: the owner is on a call with ' . $agentLabel . ' right now. Work the request in that call, or offer a schedule that calls back later.',
            AgentVoiceCallStart::REFUSED_APP_NOT_INCLUDED => 'No call: calls with ' . $agentLabel . ' are not available to this owner. Do not retry.',
            AgentVoiceCallStart::REFUSED_APP_NOT_RINGABLE => 'No call: the app cannot ring right now, no client of the owner is open. Next step: ' . static::buildOpenTheAppNextStep() . '.',
            AgentVoiceCallStart::REFUSED_APP_RING_DELIVERED_NOWHERE => 'No call: the ring reached no open client of the owner. Next step: ' . static::buildOpenTheAppNextStep() . '.',
            AgentVoiceCallStart::REFUSED_NO_AI_BUDGET => 'No call: the AI budget is used up, calls pause until there is budget again. Do not retry.',
            AgentVoiceCallStart::REFUSED_DAILY_LIMIT_REACHED => 'No call: the call time of today is used up for this account, calls are possible again tomorrow. Do not retry.',
            AgentVoiceCallStart::REFUSED_PURPOSE_NOT_CALLABLE => 'No call: a new call conversation can carry only a purpose made for calls ('
                . implode(', ', $agentVoiceCallStart->callablePurposeNames) . ').',
            AgentVoiceCallStart::REFUSED_PURPOSE_NEEDS_NEW_CONVERSATION => 'No call: a purpose is only possible for a call in a NEW conversation (conversation NEW).',
            default => static::buildRefusalOfFurtherReason($agentVoiceCallStart),
        };
    }

    /**
     * The sentence of a refusal reason the base does not word itself. In the base that is the call conversation that
     * could not be created; an application's subclass words the reasons of its further media here.
     */
    protected static function buildRefusalOfFurtherReason(AgentVoiceCallStart $agentVoiceCallStart): string
    {
        return 'No call: the call conversation could not be created.';
    }

    /** What the model is told when the app rings: how long, what to say now, and what happens when nobody answers. */
    protected static function buildRingingNote(int $ringSeconds): string
    {
        return 'The app is ringing now on every open client of the owner, for ' . $ringSeconds . ' seconds. '
            . 'Tell the owner right away that the app is ringing. '
            . 'If nobody answers, the call ends unanswered and a call-end note appears in this conversation.';
    }

    /** The next step named in a refusal when nothing can ring: what the owner does so that the app can ring. */
    protected static function buildOpenTheAppNextStep(): string
    {
        return 'the owner opens the app or keeps it open, then ' . static::getAgentLabel() . ' can ring it';
    }

    /** What the agent is called in these texts: the persona's agent name, else {@see AgentVoiceCall::DEFAULT_AGENT_LABEL}. */
    protected static function getAgentLabel(): string
    {
        $agentName = AgentVoiceConfiguration::current()->agentName;
        return $agentName !== '' ? $agentName : AgentVoiceCall::DEFAULT_AGENT_LABEL;
    }
}
