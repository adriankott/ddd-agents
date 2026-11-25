<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\Briefing;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use RuntimeException;

/**
 * The package's space service with fixed answers for a briefing: the conversation's interface language, the
 * owner addressing line (and the language it was asked in), the product name; each one can fail instead.
 */
class BriefingAgentSpacesService extends AgentSpacesService
{
    public string $userLanguageCode = 'de';

    public ?string $ownerAddressingInstruction = null;

    public bool $failsOwnerAddressing = false;

    public ?string $productNameOfConversation = null;

    public bool $failsProductName = false;

    /** @var array<int, ?string> The spoken language of every addressing request */
    public array $addressingLanguageCodes = [];

    public function resolveUserLanguageCode(AIConversation $conversation): string
    {
        return $this->userLanguageCode;
    }

    public function buildOwnerAddressingInstruction(AIConversation $conversation, ?string $spokenLanguageCode = null): ?string
    {
        $this->addressingLanguageCodes[] = $spokenLanguageCode;
        if ($this->failsOwnerAddressing) {
            throw new RuntimeException('the addressing settings cannot be read');
        }

        return $this->ownerAddressingInstruction;
    }

    public function findProductNameOfConversation(AIConversation $conversation): ?string
    {
        if ($this->failsProductName) {
            throw new RuntimeException('the product name cannot be read');
        }

        return $this->productNameOfConversation;
    }
}
