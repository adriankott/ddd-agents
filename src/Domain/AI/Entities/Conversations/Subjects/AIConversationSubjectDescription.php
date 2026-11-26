<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Subjects;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What an application says about the record a conversation is bound to
 * ({@see \DDD\Domain\AI\Services\Conversations\Subjects\AIConversationSubjectDescriber}): its display name, one line
 * for the model and where the owner opens it. Stored once on the conversation's working state when the conversation
 * is bound ({@see \DDD\Domain\AI\Entities\Conversations\Agent\AgentWorkingState::$subjectDescription}) and read from
 * there in every model call, so the text never changes under a running conversation.
 *
 * The description is the application's own wording (the kind, the id, what the record belongs to) and holds no text an
 * end user typed; the name is the record's data: the model reads it between the marks of untrusted content
 * ({@see \DDD\Domain\AI\Entities\MCPPackages\ToolResultUntrustedSegments}). All three are cleaned of control characters
 * and line breaks and cut to their caps.
 *
 * @phpstan-consistent-constructor
 */
class AIConversationSubjectDescription extends ValueObject
{
    /** @var int The characters of {@see $name} */
    public const int MAX_NAME_CHARS = 255;

    /** @var int The characters of {@see $description} */
    public const int MAX_DESCRIPTION_CHARS = 500;

    /** @var int The characters of {@see $link} */
    public const int MAX_LINK_CHARS = 1000;

    /** @var string What the marked record name is called in the model's context */
    public const string NAME_SOURCE = 'record name';

    /** @var string|null The record's display name, e.g. `Spring order 2026`; the client shows it */
    public ?string $name = null;

    /** @var string|null One line in the application's words that says what the record is, e.g. `order 10683 of shop 12`; no end-user text */
    public ?string $description = null;

    /** @var string|null Where the owner opens the record: an absolute URL or a path of the application */
    public ?string $link = null;

    /**
     * A description of one record.
     *
     * @param string $description one line for the model in the application's words, without end-user text
     * @param string|null $name the record's display name (end-user text; marked as untrusted for the model)
     * @param string|null $link an absolute URL or a path of the application
     * @return static
     */
    public static function of(string $description, ?string $name = null, ?string $link = null): static
    {
        $aIConversationSubjectDescription = new static();
        $aIConversationSubjectDescription->description = static::cleanText($description, static::MAX_DESCRIPTION_CHARS);
        $aIConversationSubjectDescription->name = static::cleanText($name, static::MAX_NAME_CHARS);
        $aIConversationSubjectDescription->link = static::cleanText($link, static::MAX_LINK_CHARS);
        return $aIConversationSubjectDescription;
    }

    /** Whether there is a line for the model. */
    public function hasDescription(): bool
    {
        return $this->description !== null && $this->description !== '';
    }

    /**
     * The text of the system segment `CONVERSATION_SUBJECT`: what the record is, its name as marked data of third
     * parties, its link, and that the lines date from the conversation's start. Pure, so the same description
     * renders the same bytes in every model call.
     *
     * Example:
     *
     *     # Record of this conversation
     *     This conversation is about one record of the application: support ticket 13082 of shop 12.
     *     Name: <untrusted-content source="record name">Ride not counted</untrusted-content>
     *     Link: /support-tickets/13082
     *     These lines were written when the conversation started. Read the record with your tools before you state its current state.
     *
     * @return string|null null without a line for the model
     */
    public function renderContextText(): ?string
    {
        if (!$this->hasDescription()) {
            return null;
        }
        $lines = [
            '# Record of this conversation',
            'This conversation is about one record of the application: ' . rtrim((string)$this->description, '.') . '.',
        ];
        if ($this->name !== null && $this->name !== '') {
            $lines[] = 'Name: ' . ToolResultUntrustedSegments::wrapSingleLine(static::NAME_SOURCE, $this->name);
        }
        if ($this->link !== null && $this->link !== '') {
            $lines[] = 'Link: ' . $this->link;
        }
        $lines[] = 'These lines were written when the conversation started. Read the record with your tools before you state its current state.';
        return implode("\n", $lines);
    }

    /** @return array<string, string|null> the stored shape on the conversation's working state */
    public function toRepositoryArray(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'link' => $this->link];
    }

    /**
     * The description a working state stores, or null when the stored value holds no line for the model.
     *
     * @param array<string, mixed> $repoArray
     * @return static|null
     */
    public static function fromRepositoryArray(array $repoArray): ?static
    {
        $aIConversationSubjectDescription = static::of(
            is_string($repoArray['description'] ?? null) ? $repoArray['description'] : '',
            is_string($repoArray['name'] ?? null) ? $repoArray['name'] : null,
            is_string($repoArray['link'] ?? null) ? $repoArray['link'] : null
        );
        return $aIConversationSubjectDescription->hasDescription() ? $aIConversationSubjectDescription : null;
    }

    /**
     * One line, without control characters and line breaks, cut to `$maxChars`; null when nothing is left.
     *
     * Example: `"  Stadt\nradeln  "` → `Stadt radeln`
     */
    protected static function cleanText(?string $text, int $maxChars): ?string
    {
        if ($text === null) {
            return null;
        }
        $cleanedText = trim((string)preg_replace('/\s+/u', ' ', AIConversationMessage::sanitizeUserText($text)));
        return $cleanedText === '' ? null : mb_substr($cleanedText, 0, $maxChars);
    }
}
