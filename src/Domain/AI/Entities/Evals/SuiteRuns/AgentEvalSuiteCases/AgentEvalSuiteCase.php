<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteCases;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentApplicationWording;
use DDD\Domain\AI\Entities\Evals\AgentEvalCaseRow;
use DDD\Domain\AI\Entities\Evals\SuiteRuns\AgentEvalSuiteRun;
use DDD\Domain\AI\Repo\DB\Evals\SuiteRuns\AgentEvalSuiteCases\DBAgentEvalSuiteCase;
use DDD\Domain\AI\Services\Eval\AgentEvalSuiteCasesService;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

/**
 * One case of a finished evaluation run ({@see AgentEvalSuiteRun}): the case id, the conversation the case drove, its
 * verdict, what went wrong in a few lines (the deterministic failures, the judge's reasons and violated criteria, the
 * error) and the numbers of its performance block. The verdict says WHAT failed, never WHY: the cause of a FAIL is read
 * from the conversation down to the context of the model call that went wrong.
 *
 * @method static AgentEvalSuiteCasesService getService()
 * @method static DBAgentEvalSuiteCase getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentEvalSuiteCase::class)]
class AgentEvalSuiteCase extends Entity
{
    use QueryOptionsTrait;

    public const string VERDICT_PASS = 'PASS';
    public const string VERDICT_FAIL = 'FAIL';
    public const string VERDICT_ERROR = 'ERROR';
    public const string VERDICT_NOT_APPLICABLE = 'NOT_APPLICABLE';
    public const string VERDICT_EXPECTED_FAIL = 'EXPECTED_FAIL';

    public const array VERDICTS = [self::VERDICT_PASS, self::VERDICT_FAIL, self::VERDICT_ERROR, self::VERDICT_NOT_APPLICABLE, self::VERDICT_EXPECTED_FAIL];

    /** @var int The longest findings text stored; a longer one is cut and ends with "…". */
    public const int FINDINGS_MAX_LENGTH = 1500;

    /** @var int|null The run the case belongs to. */
    #[NotNull]
    public ?int $agentEvalSuiteRunId = null;

    /** @var AgentEvalSuiteRun|null The run the case belongs to. */
    #[LazyLoad(repoType: LazyLoadRepo::DB, addAsParent: true)]
    public ?AgentEvalSuiteRun $agentEvalSuiteRun;

    /** @var string|null The case id of the fixture, e.g. `catalog.last-7-days`. */
    #[NotNull]
    #[Length(max: 191)]
    public ?string $caseId = null;

    /** @var string|null The suite of the case inside a suite run (e.g. tools, multistep, taskplan, selection). */
    #[Length(max: 32)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $suiteName = null;

    /** @var string|null The fixture file (without `.json`) or the skill the case belongs to. */
    #[Length(max: 128)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $fixtureName = null;

    /** @var string|null The agent model under test. */
    #[Length(max: 64)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $modelName = null;

    /** @var string|null `call` (deterministic, single-shot) or `judge` (simulated owner + judge). */
    #[Length(max: 16)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $flavor = null;

    /** @var int|null The conversation the case drove; null when the case kept no conversation (transient run, error before the start). */
    public ?int $aiConversationId = null;

    /** @var string|null PASS, FAIL, ERROR, NOT_APPLICABLE or EXPECTED_FAIL. */
    #[NotNull]
    #[Choice(choices: self::VERDICTS)]
    #[Length(max: 16)]
    public ?string $verdict = null;

    /** @var string|null What went wrong, one finding per line: failures, the judge's reasons and violated criteria, the error. Cut at 1,500 characters. */
    #[DatabaseColumn(sqlType: DatabaseColumn::SQL_TYPE_TEXT)]
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?string $findings = null;

    /** @var float|null Wall seconds of the case (its process; the judge's and the simulated owner's time included). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?float $wallSeconds = null;

    /** @var float|null Seconds the agent worked on the owner turns (the judge's and the simulated owner's time excluded). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?float $agentSeconds = null;

    /** @var int|null Owner turns of the conversation. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $ownerTurns = null;

    /** @var int|null Model calls of the whole conversation tree (sub-agents included). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $modelCalls = null;

    /** @var int|null Tool calls of the conversation. */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?int $toolCalls = null;

    /** @var float|null Cost of the agent model in USD (the judge's and the simulated owner's cost excluded). */
    #[DatabaseIndex(indexType: DatabaseIndex::TYPE_NONE)]
    public ?float $costUsd = null;

    /**
     * The case of one row of an evaluation command's result, built as the application's class when it has one at the
     * same path. Pure.
     *
     * @param AgentEvalCaseRow $caseRow
     * @param string|null $runModelName the run's model, for a row without its own `model`
     * @param string|null $runFlavor the run's flavor, for a row without its own `flavor`
     * @return static
     */
    public static function fromEvalCaseRow(AgentEvalCaseRow $caseRow, ?string $runModelName = null, ?string $runFlavor = null): static
    {
        $agentEvalSuiteCase = static::newInstance();
        $agentEvalSuiteCase->applyEvalCaseRow($caseRow, $runModelName, $runFlavor);
        return $agentEvalSuiteCase;
    }

    /**
     * Fills this case from one case row. The verdict: not applicable → NOT_APPLICABLE, passed → PASS, a case failing
     * by design → EXPECTED_FAIL, an `error` → ERROR, else FAIL. The wall seconds are the process seconds, else the
     * duration, else the conversation's wall seconds. A row without `model` / `flavor` takes the run's.
     *
     * @param AgentEvalCaseRow $caseRow
     * @param string|null $runModelName
     * @param string|null $runFlavor
     * @return void
     */
    public function applyEvalCaseRow(AgentEvalCaseRow $caseRow, ?string $runModelName = null, ?string $runFlavor = null): void
    {
        $caseRowValues = $caseRow->toArray();
        $this->caseId = mb_substr((string)($caseRowValues['case'] ?? ''), 0, 191);
        $this->suiteName = $this->readShortString($caseRowValues['suite'] ?? null, 32);
        $this->fixtureName = $this->readShortString($caseRowValues['fixture'] ?? $caseRowValues['skill'] ?? null, 128);
        $this->modelName = $this->readShortString($caseRowValues['model'] ?? $runModelName, 64);
        $this->flavor = $this->readShortString($caseRowValues['flavor'] ?? $runFlavor, 16);
        $this->aiConversationId = is_numeric($caseRowValues['conversationId'] ?? null) && (int)$caseRowValues['conversationId'] > 0
            ? (int)$caseRowValues['conversationId']
            : null;
        $this->verdict = $this->deriveVerdict($caseRow);
        $this->findings = $this->composeFindings($caseRow);
        $this->applyPerformanceNumbers($caseRow);
    }

    /**
     * The numbers of the row's performance block, with the row's own fields where the block lacks one.
     *
     * @param AgentEvalCaseRow $caseRow
     * @return void
     */
    protected function applyPerformanceNumbers(AgentEvalCaseRow $caseRow): void
    {
        $caseRowValues = $caseRow->toArray();
        $performance = $caseRow->getPerformance()->toArray();
        $this->wallSeconds = match (true) {
            is_numeric($caseRowValues['processSeconds'] ?? null) => round((float)$caseRowValues['processSeconds'], 1),
            is_numeric($caseRowValues['durationMs'] ?? null) => round((float)$caseRowValues['durationMs'] / 1000, 1),
            is_numeric($performance['conversationWallSeconds'] ?? null) => round((float)$performance['conversationWallSeconds'], 1),
            default => null,
        };
        $this->agentSeconds = is_numeric($performance['agentSeconds'] ?? null) ? round((float)$performance['agentSeconds'], 1) : null;
        $this->ownerTurns = is_numeric($performance['ownerTurns'] ?? null) ? (int)$performance['ownerTurns'] : null;
        $this->modelCalls = is_numeric($performance['modelCalls'] ?? null) ? (int)$performance['modelCalls'] : null;
        $toolCalls = $performance['toolCalls'] ?? $caseRowValues['toolCallCount'] ?? null;
        $this->toolCalls = is_numeric($toolCalls) ? (int)$toolCalls : null;
        $costUsd = $performance['costUsd'] ?? $caseRowValues['costUsd'] ?? null;
        $this->costUsd = is_numeric($costUsd) ? round((float)$costUsd, 6) : null;
    }

    /**
     * @param AgentEvalCaseRow $caseRow
     * @return string
     */
    protected function deriveVerdict(AgentEvalCaseRow $caseRow): string
    {
        $caseRowValues = $caseRow->toArray();
        return match (true) {
            $caseRow->isNotApplicable() => static::VERDICT_NOT_APPLICABLE,
            ($caseRowValues['passed'] ?? false) === true => static::VERDICT_PASS,
            ($caseRowValues['expectedFail'] ?? false) === true => static::VERDICT_EXPECTED_FAIL,
            isset($caseRowValues['error']) && (string)$caseRowValues['error'] !== '' => static::VERDICT_ERROR,
            default => static::VERDICT_FAIL,
        };
    }

    /**
     * The findings of a row, one per line: the error, the deterministic failures, the judge's reasons, its criteria that
     * were not met, its ungrounded claims, the not-applicable reason, a retry and a refused restore. Null when there is
     * nothing to say; cut at {@see FINDINGS_MAX_LENGTH}.
     *
     * @param AgentEvalCaseRow $caseRow
     * @return string|null
     */
    protected function composeFindings(AgentEvalCaseRow $caseRow): ?string
    {
        $caseRowValues = $caseRow->toArray();
        $findingLines = [];
        if (isset($caseRowValues['error']) && (string)$caseRowValues['error'] !== '') {
            $findingLines[] = 'error: ' . (string)$caseRowValues['error'];
        }
        foreach ((array)($caseRowValues['failures'] ?? []) as $failure) {
            if (is_scalar($failure) && (string)$failure !== '') {
                $findingLines[] = 'failure: ' . (string)$failure;
            }
        }
        foreach ($this->composeJudgeFindingLines($caseRow) as $judgeFindingLine) {
            $findingLines[] = $judgeFindingLine;
        }
        if (isset($caseRowValues['notApplicableDetail']) && (string)$caseRowValues['notApplicableDetail'] !== '') {
            $findingLines[] = 'not applicable: ' . (string)$caseRowValues['notApplicableDetail'];
        }
        if (isset($caseRowValues['retriedAfter']) && (string)$caseRowValues['retriedAfter'] !== '') {
            $findingLines[] = 'ran twice after an infrastructure error: ' . (string)$caseRowValues['retriedAfter'];
        }
        if (($caseRowValues['restoreFailed'] ?? false) === true) {
            $findingLines[] = 'a restore step was refused: the ' . AgentApplicationWording::current()->agentSpaceName . ' was left changed';
        }
        if ($findingLines === []) {
            return null;
        }
        $findings = implode("\n", array_map(static fn (string $findingLine): string => trim((string)preg_replace('/\s+/u', ' ', $findingLine)), $findingLines));
        return mb_strlen($findings) > static::FINDINGS_MAX_LENGTH ? mb_substr($findings, 0, static::FINDINGS_MAX_LENGTH - 1) . '…' : $findings;
    }

    /**
     * The judge's lines of a row (its `verdict` block): its reasons, its criteria that were not met and its ungrounded
     * claims.
     *
     * @param AgentEvalCaseRow $caseRow
     * @return string[]
     */
    protected function composeJudgeFindingLines(AgentEvalCaseRow $caseRow): array
    {
        $judgeVerdict = is_array($caseRow->getValue('verdict')) ? $caseRow->getValue('verdict') : [];
        $judgeFindingLines = [];
        if (is_string($judgeVerdict['reasons'] ?? null) && $judgeVerdict['reasons'] !== '') {
            $judgeFindingLines[] = 'judge: ' . $judgeVerdict['reasons'];
        }
        foreach ((array)($judgeVerdict['criteria'] ?? []) as $criterion) {
            if (!is_array($criterion) || ($criterion['status'] ?? 'met') === 'met') {
                continue;
            }
            $judgeFindingLines[] = sprintf('criterion %s: %s — %s', (string)($criterion['status'] ?? ''), (string)($criterion['name'] ?? ''), (string)($criterion['note'] ?? ''));
        }
        foreach ((array)($judgeVerdict['ungroundedClaims'] ?? []) as $ungroundedClaim) {
            if (is_scalar($ungroundedClaim) && (string)$ungroundedClaim !== '') {
                $judgeFindingLines[] = 'ungrounded claim: ' . (string)$ungroundedClaim;
            }
        }
        return $judgeFindingLines;
    }

    /**
     * A scalar as a string cut to `$maxLength`; null for an empty or a non-scalar value.
     *
     * @param mixed $value
     * @param int $maxLength
     * @return string|null
     */
    protected function readShortString(mixed $value, int $maxLength): ?string
    {
        return is_scalar($value) && (string)$value !== '' ? mb_substr((string)$value, 0, $maxLength) : null;
    }

    /** One line: case, verdict, where it ran, its conversation and its numbers. */
    public function getSummaryLine(): string
    {
        $numberParts = [];
        if ($this->wallSeconds !== null) {
            $numberParts[] = sprintf('%.1f s wall', $this->wallSeconds);
        }
        if ($this->agentSeconds !== null) {
            $numberParts[] = sprintf('%.1f s agent', $this->agentSeconds);
        }
        foreach (['owner turn' => $this->ownerTurns, 'model call' => $this->modelCalls, 'tool call' => $this->toolCalls] as $numberName => $number) {
            if ($number !== null) {
                $numberParts[] = $number . ' ' . $numberName . ($number === 1 ? '' : 's');
            }
        }
        if ($this->costUsd !== null) {
            $numberParts[] = sprintf('$%.4f', $this->costUsd);
        }
        return sprintf(
            '%s · %s · %s · %s · %s%s',
            (string)$this->caseId,
            (string)$this->verdict,
            implode('/', array_filter([$this->suiteName, $this->fixtureName], static fn (?string $part): bool => $part !== null)) ?: '-',
            trim(($this->modelName ?? '') . ' ' . ($this->flavor ?? '')) ?: '-',
            $this->aiConversationId !== null ? 'conversation ' . $this->aiConversationId : 'no conversation',
            $numberParts !== [] ? ' · ' . implode(', ', $numberParts) : ''
        );
    }

    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->id ?? spl_object_id($this));
    }
}
