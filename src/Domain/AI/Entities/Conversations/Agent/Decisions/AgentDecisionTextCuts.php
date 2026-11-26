<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Decisions;

/**
 * How a decision cuts a text it shows the decision model: to a limit, and with a marker in the text that says how much
 * is missing and where. Without the marker the model reads a fragment as the whole text (a question whose subject was
 * cut away reads as a question about nothing). A text within the limit stays as it is.
 *
 * Keep the END of an assistant text (its question and offer stand in its last sentences) and the START of an owner
 * message (its answer stands first).
 */
class AgentDecisionTextCuts
{
    /** The marker before a text whose start was cut; `%d` = the characters cut */
    public const string MARKER_CUT_AT_THE_START = '[… %d characters cut at the start] ';

    /** The marker after a text whose end was cut; `%d` = the characters cut */
    public const string MARKER_CUT_AT_THE_END = ' [… %d characters cut at the end]';

    /**
     * The last `$maxCharacters` of `$text`, after the marker of what was cut at its start.
     *
     * @param string $text
     * @param int $maxCharacters
     * @return string
     */
    public static function keepEnd(string $text, int $maxCharacters): string
    {
        $cutCharacters = static::countCutCharacters($text, $maxCharacters);
        if ($cutCharacters === 0) {
            return $text;
        }
        return sprintf(self::MARKER_CUT_AT_THE_START, $cutCharacters) . mb_substr($text, -max(0, $maxCharacters));
    }

    /**
     * The first `$maxCharacters` of `$text`, before the marker of what was cut at its end.
     *
     * @param string $text
     * @param int $maxCharacters
     * @return string
     */
    public static function keepStart(string $text, int $maxCharacters): string
    {
        $cutCharacters = static::countCutCharacters($text, $maxCharacters);
        if ($cutCharacters === 0) {
            return $text;
        }
        return mb_substr($text, 0, max(0, $maxCharacters)) . sprintf(self::MARKER_CUT_AT_THE_END, $cutCharacters);
    }

    /**
     * How many characters a cut of `$text` to `$maxCharacters` drops (0 = the text fits).
     *
     * @param string $text
     * @param int $maxCharacters
     * @return int
     */
    public static function countCutCharacters(string $text, int $maxCharacters): int
    {
        return max(0, mb_strlen($text) - max(0, $maxCharacters));
    }
}
