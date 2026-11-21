<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories\Curation;

use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Where and when the owner stated the value a new statement contradicts: the line of the conflict signal that lets the
 * agent remind the owner before it asks which value is right. The stored value and its age always; the source
 * conversation's title and a short excerpt of the original message when the value was said in another conversation.
 */
class AgentMemoryConflictProvenance extends ValueObject
{
    /** @var bool The value was said earlier in the conversation the signal goes to (the agent has it in view) */
    public bool $fromCurrentConversation = false;

    /** @var string|null The stored value the new statement contradicts */
    public ?string $contradictedMemoryContent = null;

    /** @var DateTime|null Since when the stored value is valid */
    public ?DateTime $originalStatedAt = null;

    /** @var int|null The message the stored value was said in */
    public ?int $sourceMessageId = null;

    /** @var int|null The conversation of that message */
    public ?int $sourceConversationId = null;

    /** @var string|null The title of that conversation */
    public ?string $sourceConversationTitle = null;

    /** @var string|null One or two sentences of the original message that state the value (a small model picks them) */
    public ?string $curatedOriginalExcerpt = null;

    /**
     * How long ago the value was stated, in English for the signal (`just now`, `12 minutes ago`, `3 hours ago`,
     * `5 days ago`), or null without a moment.
     *
     * @return string|null
     */
    public function describeStatedAgo(): ?string
    {
        if (!isset($this->originalStatedAt)) {
            return null;
        }
        $ageSeconds = max(0, (new DateTime('now'))->getTimestamp() - $this->originalStatedAt->getTimestamp());
        if ($ageSeconds < 90) {
            return 'just now';
        }
        $minutes = (int)round($ageSeconds / 60);
        if ($minutes < 60) {
            return "{$minutes} minutes ago";
        }
        $hours = (int)round($minutes / 60);
        if ($hours < 24) {
            return "{$hours} hours ago";
        }
        $days = (int)round($hours / 24);
        return "{$days} days ago";
    }

    /**
     * The provenance as one clause of the conflict signal.
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $statedAgo = $this->describeStatedAgo();
        $when = $statedAgo !== null ? " ({$statedAgo})" : '';
        if ($this->fromCurrentConversation) {
            $excerpt = $this->contradictedMemoryContent !== null ? ": \"{$this->contradictedMemoryContent}\"" : '';
            return "originally stated earlier in THIS conversation{$when}{$excerpt}";
        }
        $title = $this->sourceConversationTitle !== null ? " \"{$this->sourceConversationTitle}\"" : '';
        $excerpt = $this->curatedOriginalExcerpt ?? $this->contradictedMemoryContent;
        $excerptText = $excerpt !== null ? ": \"{$excerpt}\"" : '';
        return "originally stated in an earlier conversation{$title}{$when}{$excerptText}";
    }
}
