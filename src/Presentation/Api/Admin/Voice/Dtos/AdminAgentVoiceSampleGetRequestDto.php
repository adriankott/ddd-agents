<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice\Dtos;

use DDD\Presentation\Base\Dtos\RequestDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/** The request for the voice sample of one voice in one language. */
class AdminAgentVoiceSampleGetRequestDto extends RequestDto
{
    /** @var string A voice of `AI.agentVoice.samples.voiceIds` (default: the provider's selectable live voices) */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $voiceId;

    /** @var string A language that has a sample sentence (`AI.agentVoice.samples.sentences`) */
    #[Parameter(in: Parameter::PATH, required: true)]
    public string $languageCode;
}
