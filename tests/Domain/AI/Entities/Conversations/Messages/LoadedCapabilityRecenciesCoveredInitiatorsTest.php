<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationOwnerTurns;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilityRecencies;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The unused owner turns of a loaded capability are counted across a compaction cover: the turn initiators below the
 * loaded tail (the rows the cover stands for) are added when they lie after the capability's stamp and before the
 * current initiator. Without them the count is the loaded tail's only, as before; a stamp-less capability keeps the
 * turn-count fallback.
 */
class LoadedCapabilityRecenciesCoveredInitiatorsTest extends UnitTestCase
{
    /** The loaded tail after a compaction: owner turns at 50, 54 and the current one at 58. */
    protected const array LOADED_TAIL_INITIATOR_INDEXES = [50, 54, 58];

    protected const int CURRENT_INITIATOR_INDEX = 58;

    /** The turn initiators the compaction cover stands for. */
    protected const array COVERED_INITIATOR_INDEXES = [10, 20, 30, 40];

    public function testWithoutCoveredInitiatorsOnlyTheLoadedTailIsCounted(): void
    {
        $loadedCapabilities = $this->buildLoadedCapabilities();

        $loadedCapabilityRecencies = LoadedCapabilityRecencies::buildFor($loadedCapabilities, $this->buildLoadedTailOwnerTurns(), self::CURRENT_INITIATOR_INDEX, 9);

        self::assertSame(2, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('Seo.Rankings')), 'at least that old: 50 and 54');
        self::assertSame(1, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('Presence.Listings')), '54 only');
        self::assertSame(0, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('getReviews')));
    }

    public function testTheCoveredInitiatorsAfterTheStampAreCounted(): void
    {
        $loadedCapabilities = $this->buildLoadedCapabilities();

        $loadedCapabilityRecencies = LoadedCapabilityRecencies::buildFor(
            $loadedCapabilities,
            $this->buildLoadedTailOwnerTurns(),
            self::CURRENT_INITIATOR_INDEX,
            9,
            self::COVERED_INITIATOR_INDEXES
        );

        self::assertSame(5, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('Seo.Rankings')), 'stamp 10: covered 20, 30, 40 plus 50, 54');
        self::assertSame(1, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('Presence.Listings')), 'stamp 50: no covered initiator after it');
        self::assertSame(0, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('getReviews')), 'used in the newest turn before the current one');
        self::assertSame(3, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('Legacy.Skill')), 'a stamp-less capability keeps the turn-count fallback');
    }

    public function testACoveredInitiatorAtOrAfterTheCurrentInitiatorIsNotCounted(): void
    {
        $loadedCapabilities = $this->buildLoadedCapabilities();

        $loadedCapabilityRecencies = LoadedCapabilityRecencies::buildFor(
            $loadedCapabilities,
            $this->buildLoadedTailOwnerTurns(),
            self::CURRENT_INITIATOR_INDEX,
            9,
            [10, 30, 58, 70]
        );

        self::assertSame(3, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('Seo.Rankings')), '30 plus 50, 54; neither 10 (the stamp) nor 58 nor 70');
    }

    public function testWithoutACurrentInitiatorEveryCoveredInitiatorAfterTheStampCounts(): void
    {
        $loadedCapabilities = $this->buildLoadedCapabilities();

        $loadedCapabilityRecencies = LoadedCapabilityRecencies::buildFor($loadedCapabilities, $this->buildLoadedTailOwnerTurns(), null, 9, [20, 70]);

        self::assertSame(5, $loadedCapabilityRecencies->getOwnerTurnsSince($loadedCapabilities->getByName('Seo.Rankings')), '50, 54, 58 of the tail plus 20 and 70');
    }

    protected function buildLoadedCapabilities(): LoadedCapabilities
    {
        $loadedCapabilities = new LoadedCapabilities();
        $rankingsSkill = $this->buildCapability('Seo.Rankings', LoadedCapability::TYPE_SKILL, lastUsedInitiatorIndex: 10);
        $loadedCapabilities->add($rankingsSkill);
        $listingsSkill = $this->buildCapability('Presence.Listings', LoadedCapability::TYPE_SKILL, loadedAtTurnInitiatorMessageIndex: 50);
        $loadedCapabilities->add($listingsSkill);
        $reviewsTool = $this->buildCapability('getReviews', LoadedCapability::TYPE_TOOL, lastUsedInitiatorIndex: 54);
        $loadedCapabilities->add($reviewsTool);
        $legacySkill = $this->buildCapability('Legacy.Skill', LoadedCapability::TYPE_SKILL);
        $legacySkill->turnIndexAtLastUse = 5;
        $loadedCapabilities->add($legacySkill);
        return $loadedCapabilities;
    }

    protected function buildCapability(string $name, string $type, ?int $lastUsedInitiatorIndex = null, ?int $loadedAtTurnInitiatorMessageIndex = null): LoadedCapability
    {
        $loadedCapability = new LoadedCapability();
        $loadedCapability->name = $name;
        $loadedCapability->type = $type;
        $loadedCapability->lastUsedInitiatorIndex = $lastUsedInitiatorIndex;
        $loadedCapability->loadedAtTurnInitiatorMessageIndex = $loadedAtTurnInitiatorMessageIndex;
        return $loadedCapability;
    }

    protected function buildLoadedTailOwnerTurns(): AIConversationOwnerTurns
    {
        $rows = [];
        foreach (self::LOADED_TAIL_INITIATOR_INDEXES as $initiatorIndex) {
            $rows[] = $this->buildOwnerRow($initiatorIndex);
            $rows[] = $this->buildAssistantRow($initiatorIndex + 1);
        }
        return AIConversationOwnerTurns::fromRows($rows);
    }

    protected function buildOwnerRow(int $indexInConversation): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->message = 'owner turn ' . $indexInConversation;
        $message->indexInConversation = $indexInConversation;
        return $message;
    }

    protected function buildAssistantRow(int $indexInConversation): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->message = 'answer';
        $message->indexInConversation = $indexInConversation;
        return $message;
    }
}
