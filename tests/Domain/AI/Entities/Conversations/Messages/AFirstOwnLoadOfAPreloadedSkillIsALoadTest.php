<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A skill only the platform loaded (a preload) that the model asks for the first time is answered like a load: the model
 * takes it over and reads "Skills loaded". "Already loaded" to its first own load made it ask again and again. A
 * repeat after that, and a skill the model loaded itself, still get "Already loaded".
 */
class AFirstOwnLoadOfAPreloadedSkillIsALoadTest extends UnitTestCase
{
    public function testTheFirstOwnLoadOfAPreloadedSkillIsAnsweredAsALoadAndARepeatIsNot(): void
    {
        $callMessage = $this->messageWithLoadedSkill(LoadedCapability::SOURCE_DECISION_MODEL);

        self::assertSame('Skills loaded: Media.Images. Their tools are in your tool list. Call the one you need next.', $callMessage->loadSkillsFromToolArguments(['skill_names' => ['Media.Images']]));
        self::assertSame(LoadedCapability::SOURCE_MODEL, $callMessage->getLoadedCapabilities()->getByName('Media.Images')->source);
        self::assertStringStartsWith('Already loaded: Media.Images', $callMessage->loadSkillsFromToolArguments(['skill_names' => ['Media.Images']]));
    }

    public function testASkillTheModelLoadedItselfIsAlreadyLoaded(): void
    {
        $callMessage = $this->messageWithLoadedSkill(null);

        self::assertStringStartsWith('Already loaded: Media.Images', $callMessage->loadSkillsFromToolArguments(['skill_names' => ['Media.Images']]));
    }

    protected function messageWithLoadedSkill(?string $source): AIConversationMessage
    {
        $entry = ['name' => 'Media.Images', 'type' => LoadedCapability::TYPE_SKILL];
        if ($source !== null) {
            $entry['source'] = $source;
        }
        /** @var AIConversationMessage $callMessage */
        $callMessage = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $callMessage->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        $callMessage->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries(['Media.Images' => $entry]);
        return $callMessage;
    }
}
