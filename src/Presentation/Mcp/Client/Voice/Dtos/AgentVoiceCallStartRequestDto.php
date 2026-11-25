<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Voice\Dtos;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallStart;
use DDD\Infrastructure\Validation\Constraints\Choice;
use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Input of `startVoiceCall`: the call's optional task and which conversation the call runs on. The call start has no
 * REST action whose request it could share ({@see \DDD\Domain\AI\Services\Voice\AgentVoiceCallStartsService}).
 */
class AgentVoiceCallStartRequestDto extends RequestDto
{
    /** @var string|null The call's task: a COMPLETE instruction written like a user request (what to go through with the owner and what to ask). Leave it out when the owner gave no topic: the call then opens by asking what the owner needs */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 2000)]
    public ?string $task = null;

    /** @var string|null CURRENT (default): the call continues this conversation (what is said lands here). NEW: the call gets its own new conversation, only for a different goal than this chat's topic */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Choice(choices: AgentVoiceCallStart::REQUESTABLE_CONVERSATIONS)]
    public ?string $conversation = AgentVoiceCallStart::CONVERSATION_CURRENT;

    /** @var string|null NEW only: the purpose the new call conversation carries, only when the owner asked for exactly that */
    #[Parameter(in: Parameter::BODY, required: false)]
    #[Length(max: 80)]
    public ?string $purposeName = null;

    /** Whether the call gets its own new conversation (NEW) instead of continuing this one. */
    public function asksForNewConversation(): bool
    {
        return $this->conversation === AgentVoiceCallStart::CONVERSATION_NEW;
    }

    /** The task, trimmed; null when none was given. */
    public function getCallTaskOrNull(): ?string
    {
        $callTask = trim((string)($this->task ?? ''));
        return $callTask !== '' ? $callTask : null;
    }

    /** The purpose name, trimmed; null when none was given. */
    public function getPurposeNameOrNull(): ?string
    {
        $purposeName = trim((string)($this->purposeName ?? ''));
        return $purposeName !== '' ? $purposeName : null;
    }
}
