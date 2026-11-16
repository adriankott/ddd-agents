<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The verdict of the judge model on one judged run, as the judge wrote it.
 *
 * The judge answers with one JSON object whose keys the verdict prompt asks for (`verdict`, `scorePercent`, `criteria`,
 * `goalMet`, `grounded`, `toolAppropriate`, `clarificationOk`, `ungroundedClaims`, `reasons`; the compaction-tree
 * prompt asks for `treeGrew`, `rollUpsFormed`, `recencyStaircase`, `noGapsOrOverlaps`, `factSurvived`). The object is
 * kept exactly as decoded — every key, its order and its value type — because the case rows store it as JSON and a
 * suite compares it between runs ({@see toArray()}).
 *
 * Reading the raw answer ({@see fromJudgeOutput()}):
 * 1. Markdown fences are removed; the text from the first `{` to the last `}` is decoded;
 * 2. when that span is no JSON object (prose after the object), the first balanced top-level object is decoded
 *    ({@see findFirstBalancedObjectText()}, quotes and escapes respected);
 * 3. when no object decodes but the head carries `"verdict": "PASS|FAIL"`, the answer was cut off: the leading verdict
 *    scalars are salvaged and the verdict is marked as truncated ({@see isTruncated()}) — the criteria are lost, so a
 *    caller judges the run once more;
 * 4. otherwise the answer is unparseable: a FAIL marked as such ({@see isUnparseable()}).
 *
 * No I/O; the model call and its retries belong to the judged-run service.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalJudgeVerdict extends ValueObject
{
    /** @var string The verdict of a run that met every required criterion */
    public const string VERDICT_PASS = 'PASS';

    /** @var string The verdict of a run that violated a required criterion, and of an unparseable answer */
    public const string VERDICT_FAIL = 'FAIL';

    /** @var string No verdict: the evaluation itself failed (e.g. the answer was cut off twice) */
    public const string VERDICT_ERROR = 'ERROR';

    /** @var string The key of the verdict */
    public const string KEY_VERDICT = 'verdict';

    /** @var string The key of the reasons sentence */
    public const string KEY_REASONS = 'reasons';

    /** @var string The key of the score in percent */
    public const string KEY_SCORE_PERCENT = 'scorePercent';

    /** @var string The key that marks a verdict salvaged from a cut-off answer */
    public const string KEY_JUDGE_OUTPUT_TRUNCATED = 'judgeOutputTruncated';

    /** @var string The key that marks an answer no verdict could be read from */
    public const string KEY_JUDGE_OUTPUT_UNPARSEABLE = 'judgeOutputUnparseable';

    /** @var string The key of the head of the raw answer kept beside a salvaged or unparseable verdict */
    public const string KEY_RAW = 'raw';

    /** @var string[] The boolean verdict fields salvaged from the head of a cut-off answer, in this order */
    public const array SALVAGED_BOOLEAN_FIELD_NAMES = ['goalMet', 'grounded', 'toolAppropriate', 'clarificationOk'];

    /** @var int How much of the raw answer a salvaged verdict keeps */
    public const int SALVAGED_RAW_LENGTH = 1500;

    /** @var int How much of the raw answer an unparseable verdict keeps */
    public const int UNPARSEABLE_RAW_LENGTH = 500;

    /** @var array<string, mixed> The verdict as the judge wrote it (or as salvaged), keys in their order */
    protected array $verdictFields = [];

    /**
     * The verdict of the decoded verdict object `$verdictFields`, kept as it is.
     *
     * @param array<string, mixed> $verdictFields
     * @return static
     */
    public static function fromArray(array $verdictFields): static
    {
        $agentEvalJudgeVerdict = static::newInstance();
        $agentEvalJudgeVerdict->verdictFields = $verdictFields;
        return $agentEvalJudgeVerdict;
    }

    /**
     * The verdict the raw answer `$judgeOutput` of the judge model carries: the decoded object, a verdict salvaged from
     * a cut-off answer, or an unparseable FAIL (see the class description).
     */
    public static function fromJudgeOutput(string $judgeOutput): static
    {
        // markdown fences around the JSON are noise of the judge model, not content
        $cleanedOutput = preg_replace('/```(?:json)?/i', '', $judgeOutput) ?? $judgeOutput;
        $decodedVerdictFields = static::decodeVerdictObject($cleanedOutput);
        if ($decodedVerdictFields !== null) {
            return static::fromArray($decodedVerdictFields);
        }
        $salvagedVerdictFields = static::salvageVerdictFieldsOfCutOffOutput($cleanedOutput, $judgeOutput);
        if ($salvagedVerdictFields !== null) {
            return static::fromArray($salvagedVerdictFields);
        }
        return static::fromArray([
            static::KEY_VERDICT => static::VERDICT_FAIL,
            'goalMet' => false,
            'grounded' => false,
            static::KEY_REASONS => 'Judge output not parseable',
            static::KEY_JUDGE_OUTPUT_UNPARSEABLE => true,
            static::KEY_RAW => mb_substr($judgeOutput, 0, static::UNPARSEABLE_RAW_LENGTH),
        ]);
    }

    /**
     * The verdict object in the cleaned answer: the span from the first `{` to the last `}`, else the first balanced
     * top-level object. Null when no JSON object decodes.
     *
     * @return array<string, mixed>|null
     */
    public static function decodeVerdictObject(string $cleanedOutput): ?array
    {
        $objectStart = strpos($cleanedOutput, '{');
        if ($objectStart === false) {
            return null;
        }
        $objectEnd = strrpos($cleanedOutput, '}');
        if ($objectEnd !== false && $objectEnd >= $objectStart) {
            $decodedSpan = json_decode(substr($cleanedOutput, $objectStart, $objectEnd - $objectStart + 1), true);
            if (is_array($decodedSpan)) {
                return $decodedSpan;
            }
        }
        // prose after the object makes the span up to the last `}` invalid while the object itself is fine
        $balancedObjectText = static::findFirstBalancedObjectText($cleanedOutput, $objectStart);
        if ($balancedObjectText === null) {
            return null;
        }
        $decodedObject = json_decode($balancedObjectText, true);
        return is_array($decodedObject) ? $decodedObject : null;
    }

    /**
     * The text of the first balanced top-level object that starts at `$objectStart` (a `{`): braces inside quoted
     * strings and escaped quotes are skipped. Null when the object never closes.
     */
    public static function findFirstBalancedObjectText(string $text, int $objectStart): ?string
    {
        $braceDepth = 0;
        $insideString = false;
        $escapedCharacter = false;
        $textLength = strlen($text);
        for ($characterIndex = $objectStart; $characterIndex < $textLength; $characterIndex++) {
            $character = $text[$characterIndex];
            if ($insideString) {
                if ($escapedCharacter) {
                    $escapedCharacter = false;
                } elseif ($character === '\\') {
                    $escapedCharacter = true;
                } elseif ($character === '"') {
                    $insideString = false;
                }
                continue;
            }
            if ($character === '"') {
                $insideString = true;
            } elseif ($character === '{') {
                $braceDepth++;
            } elseif ($character === '}') {
                $braceDepth--;
                if ($braceDepth === 0) {
                    return substr($text, $objectStart, $characterIndex - $objectStart + 1);
                }
            }
        }
        return null;
    }

    /**
     * The verdict scalars of an answer that was cut off: the verdict prompt has them lead the object, so the head
     * still carries what the judge decided. Null when the head carries no `"verdict": "PASS|FAIL"`.
     *
     * @param string $cleanedOutput the answer without markdown fences
     * @param string $judgeOutput the answer as the judge wrote it (its head is kept under `raw`)
     * @return array<string, mixed>|null
     */
    public static function salvageVerdictFieldsOfCutOffOutput(string $cleanedOutput, string $judgeOutput): ?array
    {
        if (preg_match('/"verdict"\s*:\s*"(PASS|FAIL)"/', $cleanedOutput, $verdictMatch) !== 1) {
            return null;
        }
        $salvagedVerdictFields = [
            static::KEY_VERDICT => $verdictMatch[1],
            static::KEY_JUDGE_OUTPUT_TRUNCATED => true,
            static::KEY_REASONS => 'Judge JSON truncated/unparseable — verdict scalars salvaged from the head.',
            static::KEY_RAW => mb_substr($judgeOutput, 0, static::SALVAGED_RAW_LENGTH),
        ];
        if (preg_match('/"scorePercent"\s*:\s*(\d{1,3})/', $cleanedOutput, $scoreMatch) === 1) {
            $salvagedVerdictFields[static::KEY_SCORE_PERCENT] = (int)$scoreMatch[1];
        }
        foreach (static::SALVAGED_BOOLEAN_FIELD_NAMES as $booleanFieldName) {
            if (preg_match('/"' . $booleanFieldName . '"\s*:\s*(true|false)/', $cleanedOutput, $booleanMatch) === 1) {
                $salvagedVerdictFields[$booleanFieldName] = $booleanMatch[1] === 'true';
            }
        }
        if (preg_match('/"reasons"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', $cleanedOutput, $reasonsMatch) === 1) {
            $salvagedVerdictFields[static::KEY_REASONS] = stripcslashes($reasonsMatch[1]);
        }
        return $salvagedVerdictFields;
    }

    /**
     * The verdict exactly as the judge wrote it (or as salvaged): the form the case rows store.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->verdictFields;
    }

    /** The value of `$key` in the verdict; null when the verdict does not carry it. */
    public function getValue(string $key): mixed
    {
        return $this->verdictFields[$key] ?? null;
    }

    /** `verdict`: PASS, FAIL, ERROR or what the judge wrote; null when the object names none. */
    public function getVerdict(): ?string
    {
        $verdict = $this->verdictFields[static::KEY_VERDICT] ?? null;
        return $verdict !== null ? (string)$verdict : null;
    }

    /** Whether the judge passed the run. */
    public function isPass(): bool
    {
        return ($this->verdictFields[static::KEY_VERDICT] ?? null) === static::VERDICT_PASS;
    }

    /** `scorePercent` as the judge wrote it; null when absent. */
    public function getScorePercent(): ?int
    {
        return isset($this->verdictFields[static::KEY_SCORE_PERCENT]) ? (int)$this->verdictFields[static::KEY_SCORE_PERCENT] : null;
    }

    /** `reasons`; empty when absent. */
    public function getReasons(): string
    {
        return (string)($this->verdictFields[static::KEY_REASONS] ?? '');
    }

    /** Whether the answer was cut off and only its leading verdict scalars were salvaged. */
    public function isTruncated(): bool
    {
        return ($this->verdictFields[static::KEY_JUDGE_OUTPUT_TRUNCATED] ?? false) === true;
    }

    /** Whether no verdict could be read from the answer at all. */
    public function isUnparseable(): bool
    {
        return ($this->verdictFields[static::KEY_JUDGE_OUTPUT_UNPARSEABLE] ?? false) === true;
    }

    /**
     * The error of a run whose verdict is an evaluation error ({@see VERDICT_ERROR}: the judge gave no verdict, e.g.
     * its answer was cut off twice): `judge evaluation error: <reasons>`, so the roll-ups count the case as an error,
     * not as a failed case. Null for every other verdict.
     */
    public function findEvaluationError(): ?string
    {
        if ($this->getVerdict() !== static::VERDICT_ERROR) {
            return null;
        }
        return 'judge evaluation error: ' . $this->getReasons();
    }

    /**
     * Turns the verdict into an evaluation error: `verdict` becomes ERROR and `reasons` says why. Every other key stays
     * where it is (a salvaged verdict keeps its truncation flag and its raw head).
     *
     * @param string $reasons why the run has no verdict
     * @return void
     */
    public function markAsEvaluationError(string $reasons): void
    {
        $this->verdictFields[static::KEY_VERDICT] = static::VERDICT_ERROR;
        $this->verdictFields[static::KEY_REASONS] = $reasons;
    }
}
