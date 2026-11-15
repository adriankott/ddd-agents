<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Chat;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * What the full-screen chat shows between two terminal events: whether the screen loop goes on, the text typed into
 * the input line and the first transcript row in view. Each change returns a new state; the scroll offset is clamped
 * to the transcript when the screen is drawn ({@see self::withScrollOffsetAtMost()}).
 *
 * A plain object of one chat session; no container service.
 */
#[Exclude]
class ChatScreenState
{
    /** @var int The scroll offset that jumps to the bottom of the transcript (clamped on the next draw) */
    public const int SCROLL_OFFSET_BOTTOM = PHP_INT_MAX;

    /**
     * @param bool $isRunning false = the screen loop ends after this event
     * @param string $inputText the text typed into the input line, not sent yet
     * @param int $scrollOffset the transcript row shown first
     */
    public function __construct(
        public readonly bool $isRunning,
        public readonly string $inputText,
        public readonly int $scrollOffset,
    ) {
    }

    /**
     * The state a session starts with: running, nothing typed, the transcript at its top.
     *
     * @return self
     */
    public static function atStart(): self
    {
        return new self(true, '', 0);
    }

    /**
     * @param string $typedText the characters a key added to the input line
     * @return self
     */
    public function withTypedText(string $typedText): self
    {
        return new self($this->isRunning, $this->inputText . $typedText, $this->scrollOffset);
    }

    /**
     * @return self the state with the last character of the input line removed
     */
    public function withoutLastTypedCharacter(): self
    {
        return new self($this->isRunning, mb_substr($this->inputText, 0, -1), $this->scrollOffset);
    }

    /**
     * @param int $scrollOffset the transcript row to show first (never below 0)
     * @return self
     */
    public function withScrollOffset(int $scrollOffset): self
    {
        return new self($this->isRunning, $this->inputText, max(0, $scrollOffset));
    }

    /**
     * @param int $maxScrollOffset the highest offset the transcript allows at the current height
     * @return self the state with its scroll offset no higher than `$maxScrollOffset`
     */
    public function withScrollOffsetAtMost(int $maxScrollOffset): self
    {
        return $this->withScrollOffset(min($this->scrollOffset, $maxScrollOffset));
    }

    /**
     * @return self the state after the input line was sent: the line empty, the transcript at its bottom
     */
    public function afterInputSent(): self
    {
        return new self($this->isRunning, '', self::SCROLL_OFFSET_BOTTOM);
    }

    /**
     * @return self the state that ends the screen loop
     */
    public function stopped(): self
    {
        return new self(false, $this->inputText, $this->scrollOffset);
    }
}
