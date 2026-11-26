<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\Common\Entities\Files\Sources\FileReference;

/**
 * Pictures of any file source to look at or to pick from: tiles with the letters A, B, C …, a lightbox, inline in the
 * message or in the canvas. The block carries file references only (`media/901`, `drive/812`); the component loads
 * each picture through the application's file route under the viewer's rights (a THUMBNAIL for the tile, a PREVIEW
 * for the lightbox), so a block never holds a URL and a viewer without the right sees no picture.
 *
 * With `selection` `one` or `many` the owner picks and the pick arrives as a FRONTEND_WIDGET_CLICK signal that names
 * the wire name, the gallery's `name` when it has one, and each picked letter with its reference:
 * `ImageGallery: the operator picked A (media/901)`; several:
 * `ImageGallery: the operator picked 2 pictures: A (media/901), C (media/903)`; none of them:
 * `ImageGallery: the operator declined all pictures`. The sentences are a stored contract pinned by
 * `image-gallery-widget-contract.json` (web/src/widgets/ImageGalleryConversationWidget/); never reword them.
 */
class ImageGalleryConversationWidget extends ConversationWidget implements AnswerableConversationWidget
{
    public const string WIDGET_TYPE = 'ImageGallery';

    public const bool AWAITS_OWNER_INPUT = true;

    public const bool AWAITS_OWNER_INPUT_DEPENDS_ON_CONTENT = true;

    public const string OWNER_CONTROL = 'pick one or several pictures, enlarge, or decline all';

    public const string SUMMARY = 'Pictures of files as lettered tiles (A, B, C …) the owner enlarges and, when asked to, picks from; the pick names each letter with its file reference.';

    public const string WHEN_TO_USE = 'The owner should see pictures or choose among them: a tool writes the gallery with file references (`media/<id>`, `drive/<id>`). Write one short line, never describe or list the pictures again. The pick arrives as a signal naming the letter and the reference, e.g. `ImageGallery: the operator picked A (media/901)`: take the reference from it, never map a letter back yourself.';

    public const bool READS_WIDGET_SELF_HYDRATION_KEY = true;

    /** @var string No pick: pictures to look at */
    public const string SELECTION_NONE = 'none';

    /** @var string One picture is picked */
    public const string SELECTION_ONE = 'one';

    /** @var string Several pictures are picked */
    public const string SELECTION_MANY = 'many';

    /** @var string[] */
    public const array SELECTIONS = [self::SELECTION_NONE, self::SELECTION_ONE, self::SELECTION_MANY];

    /** @var string In the message */
    public const string DISPLAY_INLINE = 'inline';

    /** @var string Beside the chat, as a canvas */
    public const string DISPLAY_CANVAS = 'canvas';

    /** @var int The most pictures of one gallery (the letters A to L) */
    public const int MAX_FILES = 12;

    /** @var int The longest label and caption of a tile */
    public const int MAX_TEXT_LENGTH = 140;

    /** @var string The letters of the tiles, by position */
    public const string LETTERS = 'ABCDEFGHIJKL';

    /** @var string How every reaction sentence starts after the wire name and the optional name */
    public const string PICKED_SENTENCE_START = ': the operator picked ';

    /** @var string The decline sentence after the wire name and the optional name */
    public const string DECLINE_SENTENCE_END = ': the operator declined all pictures';

    /** @var string A picked letter with its reference in a sentence, e.g. `A (media/901)` */
    public const string PICKED_LETTER_PATTERN = '/\b([A-L]) \(([a-z][a-z0-9-]{0,31}\/[A-Za-z0-9_-]{1,128})\)/';

    /** @var string|null The headline above the tiles, in the owner's language */
    public ?string $title = null;

    /**
     * @var string|null What the gallery is for, as the reader of its pick knows it (`pick-REWARD_PHOTO-8841`): named in
     *     every reaction sentence, so two galleries on screen are told apart. `[A-Za-z0-9_.:-]`, at most 80 characters.
     */
    public ?string $name = null;

    /** @var string|null `none` (default), `one` or `many` */
    public ?string $selection = null;

    /** @var string|null `inline` (default) or `canvas` */
    public ?string $display = null;

    /** @var array[] The pictures, each `{reference, label?, caption?}`, at most 12, in letter order */
    public array $files = [];

    /** Waits for the owner only when a pick is asked for. */
    public function awaitsOwnerInput(): bool
    {
        return $this->selection === self::SELECTION_ONE || $this->selection === self::SELECTION_MANY;
    }

    /**
     * Adds one picture; a text that names no file reference, or a thirteenth picture, is left out.
     *
     * @return bool Whether the picture was added
     */
    public function addFile(string $reference, ?string $label = null, ?string $caption = null): bool
    {
        if (count($this->files) >= self::MAX_FILES || FileReference::tryFromString($reference) === null) {
            return false;
        }
        $file = ['reference' => $reference];
        if ($label !== null && trim($label) !== '') {
            $file['label'] = static::cutText($label);
        }
        if ($caption !== null && trim($caption) !== '') {
            $file['caption'] = static::cutText($caption);
        }
        $this->files[] = $file;
        return true;
    }

    /** @return string|null The letter of the picture at a zero-based position; null beyond the twelfth */
    public static function letterOfPosition(int $position): ?string
    {
        return $position >= 0 && $position < self::MAX_FILES ? self::LETTERS[$position] : null;
    }

    /** The sentence start that names THIS gallery: the wire name and its name when it has one. */
    public function buildSentencePrefix(): string
    {
        return self::WIDGET_TYPE . ($this->name !== null && $this->name !== '' ? ' ' . $this->name : '');
    }

    /**
     * The pick sentence the component sends for picked letters with their references, in letter order.
     *
     * @param array<string, string> $referencesByLetter
     */
    public function buildPickSentence(array $referencesByLetter): string
    {
        ksort($referencesByLetter);
        $pickedParts = [];
        foreach ($referencesByLetter as $letter => $reference) {
            $pickedParts[] = $letter . ' (' . $reference . ')';
        }
        return $this->buildSentencePrefix() . self::PICKED_SENTENCE_START
            . (count($pickedParts) === 1 ? $pickedParts[0] : count($pickedParts) . ' pictures: ' . implode(', ', $pickedParts));
    }

    public function buildDeclineSentence(): string
    {
        return $this->buildSentencePrefix() . self::DECLINE_SENTENCE_END;
    }

    /** A widget click that starts with this gallery's prefix and a colon (the type and, when set, its name). */
    public function isReactionToThisWidget(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        return $conversationWidgetReaction->isWidgetClickSignal()
            && str_starts_with(trim($conversationWidgetReaction->text), $this->buildSentencePrefix() . ':');
    }

    /** The picked letters and references, each as the gallery holds it; null for a decline or a sentence without a pick. */
    public function readAnswer(ConversationWidgetReaction $conversationWidgetReaction): ?ImageGalleryPick
    {
        $text = trim($conversationWidgetReaction->text);
        if (!str_starts_with($text, $this->buildSentencePrefix() . self::PICKED_SENTENCE_START)) {
            return null;
        }
        preg_match_all(self::PICKED_LETTER_PATTERN, substr($text, strlen($this->buildSentencePrefix())), $letterMatches, PREG_SET_ORDER);
        $imageGalleryPick = new ImageGalleryPick();
        $imageGalleryPick->reactionIndexInConversation = $conversationWidgetReaction->indexInConversation;
        foreach ($letterMatches as [, $letter, $reference]) {
            $position = strpos(self::LETTERS, $letter);
            $shownReference = $position !== false ? ($this->files[$position]['reference'] ?? null) : null;
            // a letter counts when the gallery shows that reference under it; a gallery read without its files trusts the sentence
            if ($this->files !== [] && $shownReference !== $reference) {
                continue;
            }
            $imageGalleryPick->letters[] = $letter;
            $imageGalleryPick->fileReferences[] = $reference;
        }
        return $imageGalleryPick->fileReferences !== [] ? $imageGalleryPick : null;
    }

    public function isDeclined(ConversationWidgetReaction $conversationWidgetReaction): bool
    {
        return $conversationWidgetReaction->isWidgetClickSignal()
            && trim($conversationWidgetReaction->text) === $this->buildDeclineSentence();
    }

    /** The fields in block order; an optional field left null is not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'title' => $this->title,
            'name' => $this->name,
            'selection' => $this->selection,
            'display' => $this->display,
            'files' => array_values(array_slice($this->files, 0, self::MAX_FILES)),
        ]);
    }

    protected static function cutText(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        // a fence inside a string would end the block
        $text = str_replace('```', "'''", $text);
        return mb_strlen($text) > self::MAX_TEXT_LENGTH ? mb_substr($text, 0, self::MAX_TEXT_LENGTH - 1) . '…' : $text;
    }
}
