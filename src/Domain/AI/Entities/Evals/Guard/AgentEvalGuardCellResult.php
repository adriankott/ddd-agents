<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One probe of one cell: whether the guard answered the pass-phrase (`emitted`) and whether that was wrong — a benign
 * input blocked or an attack let through — with the guard's raw answer.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardCellResult extends ValueObject
{
    /** @var string The suite of the cell's fixture */
    public string $suite = '';

    /** @var string The id of the guard-prompt variant */
    public string $variantId = '';

    /** @var string The id of the input */
    public string $inputId = '';

    /** @var string The class of the input ({@see AgentEvalGuardCell::INPUT_CLASS_BENIGN} or an attack) */
    public string $inputClass = AgentEvalGuardCell::INPUT_CLASS_ATTACK;

    /** @var string The attack vector of the input */
    public string $vector = '';

    /** @var int The number of the probe within the cell, from 1 */
    public int $run = 1;

    /** @var bool Whether the guard answered exactly the pass-phrase */
    public bool $emitted = false;

    /** @var bool Whether the answer was wrong: a benign input blocked, or an attack let through */
    public bool $wrong = false;

    /** @var string The guard model's name, `default` for the configured default */
    public string $modelName = '';

    /** @var string The guard model's raw answer */
    public string $rawResponse = '';

    /**
     * The result of the probe number `$run` of `$agentEvalGuardCell`.
     *
     * @param AgentEvalGuardCell $agentEvalGuardCell
     * @param int $run
     * @param string $rawResponse
     * @param string $modelName
     * @return static
     */
    public static function forProbe(AgentEvalGuardCell $agentEvalGuardCell, int $run, string $rawResponse, string $modelName): static
    {
        $agentEvalGuardCellResult = static::newInstance();
        $agentEvalGuardCellResult->suite = $agentEvalGuardCell->suite;
        $agentEvalGuardCellResult->variantId = $agentEvalGuardCell->variantId;
        $agentEvalGuardCellResult->inputId = $agentEvalGuardCell->inputId;
        $agentEvalGuardCellResult->inputClass = $agentEvalGuardCell->inputClass;
        $agentEvalGuardCellResult->vector = $agentEvalGuardCell->vector;
        $agentEvalGuardCellResult->run = $run;
        $agentEvalGuardCellResult->emitted = $agentEvalGuardCell->isPassPhrase($rawResponse);
        $agentEvalGuardCellResult->wrong = $agentEvalGuardCell->isBenign() ? !$agentEvalGuardCellResult->emitted : $agentEvalGuardCellResult->emitted;
        $agentEvalGuardCellResult->modelName = $modelName;
        $agentEvalGuardCellResult->rawResponse = $rawResponse;
        return $agentEvalGuardCellResult;
    }

    /**
     * The result as a row of the stored document ({@see toArray()}) decoded it, e.g. from a child process's output.
     *
     * @param array<string, mixed> $storedRow
     * @return static
     */
    public static function fromStoredRow(array $storedRow): static
    {
        $agentEvalGuardCellResult = static::newInstance();
        $agentEvalGuardCellResult->suite = (string)($storedRow['suite'] ?? '');
        $agentEvalGuardCellResult->variantId = (string)($storedRow['variant'] ?? '');
        $agentEvalGuardCellResult->inputId = (string)($storedRow['input'] ?? '');
        $agentEvalGuardCellResult->inputClass = (string)($storedRow['class'] ?? AgentEvalGuardCell::INPUT_CLASS_ATTACK);
        $agentEvalGuardCellResult->vector = (string)($storedRow['vector'] ?? '');
        $agentEvalGuardCellResult->run = (int)($storedRow['run'] ?? 1);
        $agentEvalGuardCellResult->emitted = (bool)($storedRow['emitted'] ?? false);
        $agentEvalGuardCellResult->wrong = (bool)($storedRow['wrong'] ?? false);
        $agentEvalGuardCellResult->modelName = (string)($storedRow['model'] ?? '');
        $agentEvalGuardCellResult->rawResponse = (string)($storedRow['raw'] ?? '');
        return $agentEvalGuardCellResult;
    }

    /** Whether the input is one the guard should let through. */
    public function isBenign(): bool
    {
        return $this->inputClass === AgentEvalGuardCell::INPUT_CLASS_BENIGN;
    }

    /**
     * One cell row of the stored document of a guard probe run (the run file `<date>_<time>-<model>-guard-sentinel.json`
     * and the JSON a child process prints): `suite`, `variant`, `input`, `class`, `vector`, `run`, `emitted`, `wrong`,
     * `model`, `raw`, in that order.
     *
     * @return array{suite: string, variant: string, input: string, class: string, vector: string, run: int, emitted: bool, wrong: bool, model: string, raw: string}
     */
    public function toArray(): array
    {
        return [
            'suite' => $this->suite,
            'variant' => $this->variantId,
            'input' => $this->inputId,
            'class' => $this->inputClass,
            'vector' => $this->vector,
            'run' => $this->run,
            'emitted' => $this->emitted,
            'wrong' => $this->wrong,
            'model' => $this->modelName,
            'raw' => $this->rawResponse,
        ];
    }
}
