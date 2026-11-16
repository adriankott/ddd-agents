<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\RunReport;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The judge verdict of ONE evaluated run as its evaluation log states it: the case, `PASS` or `FAIL`, the score and the
 * finding lines (`VIOLATED` / `PARTIAL`). A run without a log has the empty verdict ({@see none()}).
 *
 * A plain object of one printout; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentRunReportVerdict extends ValueObject
{
    /** @var string The verdict of a passed run */
    public const string VERDICT_PASS = 'PASS';

    /** @var int The characters of a finding line kept after its marker */
    public const int FINDING_TEXT_MAX_LENGTH = 260;

    /** @var int The conversation of the run; 0 for the empty verdict */
    public int $aiConversationId = 0;

    /** @var string The case id the verdict line names */
    public string $caseId = '';

    /** @var string `PASS`, `FAIL`, or empty without a verdict */
    public string $verdict = '';

    /** @var int|null The score in percent; null without a verdict */
    public ?int $scorePercent = null;

    /** @var string[] The finding lines, each its marker and up to {@see self::FINDING_TEXT_MAX_LENGTH} characters */
    public array $findings = [];

    /**
     * The verdict of no log: no case, no verdict, no score, no finding.
     *
     * @return static
     */
    public static function none(): static
    {
        return static::newInstance();
    }

    /**
     * @param int $aiConversationId
     * @param string $caseId
     * @param string $verdict
     * @param int $scorePercent
     * @param string[] $findings
     * @return static
     */
    public static function forRun(int $aiConversationId, string $caseId, string $verdict, int $scorePercent, array $findings): static
    {
        $runVerdict = static::newInstance();
        $runVerdict->aiConversationId = $aiConversationId;
        $runVerdict->caseId = $caseId;
        $runVerdict->verdict = $verdict;
        $runVerdict->scorePercent = $scorePercent;
        $runVerdict->findings = $findings;
        return $runVerdict;
    }

    /** The key of the verdict of a conversation in its set. */
    public static function uniqueKeyForConversationId(int $aiConversationId): string
    {
        return self::class . '_' . $aiConversationId;
    }

    public function uniqueKey(): string
    {
        return $this->aiConversationId > 0 ? static::uniqueKeyForConversationId($this->aiConversationId) : parent::uniqueKey();
    }

    /** Whether the log stated a verdict. */
    public function hasVerdict(): bool
    {
        return $this->verdict !== '';
    }

    /** Whether the run passed. */
    public function isPass(): bool
    {
        return $this->verdict === self::VERDICT_PASS;
    }

    /**
     * The judge cell of the run's row: the verdict and its score (`FAIL 63 %`); empty without a verdict.
     *
     * @return string
     */
    public function getJudgeCellText(): string
    {
        if (!$this->hasVerdict()) {
            return '';
        }
        return $this->verdict . ($this->scorePercent !== null ? ' ' . $this->scorePercent . ' %' : '');
    }
}
