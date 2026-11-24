<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\Common\Exceptions;

use DDD\Infrastructure\Exceptions\BadRequestException;

/**
 * The refusal of a writing tool of the admin agent: nothing was written. One wording for every write
 * :
 *
 *     Not saved: <reason>. <the one next move>.
 *
 * A client error of the framework (400), so the tool executor hands the message to the model as written
 * ("Execution failed for Tool '<tool>': Not saved: …") and marks the result as an error; an exception of another kind
 * would reach the model as an issue id only.
 *
 * The texts are pinned verbatim by AdminToolTextsTest: a model acts on the exact sentence, so a change is a reviewed
 * change.
 *
 * @phpstan-consistent-constructor
 */
class AdminToolNotSavedException extends BadRequestException
{
    /**
     * @var string What every refusal starts with. "Not saved" first, because a refusal that opened with the reason
     * was read as a note beside a successful write (reference application, value edits).
     */
    public const string NOT_SAVED_PREFIX = 'Not saved: ';

    /** @var string An id that reads no record: the reason and the one next move (the find tool that resolves a name to ids) */
    public const string UNKNOWN_RECORD = 'no %s with id %s. Find it with %s.';

    /** @var string A required argument that was not passed (the schema has no required list, the tool refuses) */
    public const string MISSING_ARGUMENT = '%s is missing. Pass %s.';

    /**
     * @var string A value together with its clear flag: the call contradicts itself, so nothing is saved rather than
     * one of the two guessed.
     */
    public const string VALUE_WITH_CLEAR_FLAG = '%1$s and clear%2$s were passed together. Pass the new %1$s, or clear%2$s alone to remove it.';

    /** @var string The same item in `add` and in `remove` of a list edit */
    public const string ITEM_IN_ADD_AND_REMOVE = '%s is in both add and remove. Name each item in one of the two only.';

    /**
     * @param string $reason Why nothing was saved, without a full stop
     * @param string $nextMove The one thing to do next, as a sentence
     * @return static
     */
    public static function because(string $reason, string $nextMove): static
    {
        return new static(static::NOT_SAVED_PREFIX . rtrim(trim($reason), '.') . '. ' . rtrim(trim($nextMove), '.') . '.');
    }

    /**
     * @param string $entityLabel e.g. "order"
     * @param int|string|null $entityId
     * @param string $findToolName The tool that turns a name into ids, e.g. "findOrders"
     * @return static
     */
    public static function unknownRecord(string $entityLabel, int|string|null $entityId, string $findToolName): static
    {
        return new static(static::NOT_SAVED_PREFIX . sprintf(static::UNKNOWN_RECORD, $entityLabel, $entityId ?? 'none', $findToolName));
    }

    /**
     * @param string $argumentName
     * @param string $whatToPass e.g. "the id of the order"
     * @return static
     */
    public static function missingArgument(string $argumentName, string $whatToPass): static
    {
        return new static(static::NOT_SAVED_PREFIX . sprintf(static::MISSING_ARGUMENT, $argumentName, $whatToPass));
    }

    /**
     * @param string $fieldName e.g. "slug" (the flag is clearSlug)
     * @return static
     */
    public static function valueWithClearFlag(string $fieldName): static
    {
        return new static(static::NOT_SAVED_PREFIX . sprintf(static::VALUE_WITH_CLEAR_FLAG, $fieldName, ucfirst($fieldName)));
    }

    /**
     * @param string $item The item as the caller named it
     * @return static
     */
    public static function itemInAddAndRemove(string $item): static
    {
        return new static(static::NOT_SAVED_PREFIX . sprintf(static::ITEM_IN_ADD_AND_REMOVE, $item));
    }
}
