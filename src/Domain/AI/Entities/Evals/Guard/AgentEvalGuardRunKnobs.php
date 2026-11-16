<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * What one run of the guard probe evaluation measures, beside the model: the runs per cell, the filters, the knobs
 * that replace the fixtures' variants, pass-phrase or inputs (one candidate guard prompt, one ad-hoc input), the one
 * fixture file, and — in a child process — the cells the child is restricted to. A child gets the knobs that decide
 * which cells exist as options ({@see buildChildOptionArguments()}), so it builds the same cell keys as its parent.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardRunKnobs extends ValueObject
{
    /** @var string The input class of an ad-hoc input when none is named */
    public const string DEFAULT_INPUT_CLASS = AgentEvalGuardCell::INPUT_CLASS_ATTACK;

    /** @var int How often each cell is probed (non-determinism sampling) */
    public int $runsPerCell = 1;

    /** @var string|null The candidate guard prompt file (absolute when it exists); null = the fixtures' variants */
    public ?string $guardFile = null;

    /** @var string|null The text of the candidate guard prompt file; null when no file was named or it does not exist */
    public ?string $guardPromptText = null;

    /** @var string|null The pass-phrase replacing the fixtures' pass-phrase; null = the fixtures' */
    public ?string $passPhraseOverride = null;

    /** @var string|null The one ad-hoc input replacing the fixtures' inputs; null = the fixtures' */
    public ?string $inputText = null;

    /** @var string The class of the ad-hoc input: `benign` or `attack` */
    public string $inputClass = self::DEFAULT_INPUT_CLASS;

    /** @var string|null The one fixture file (absolute when it exists); null = every configured fixture */
    public ?string $fixtureFile = null;

    /** @var string|null Only the guard-prompt variant with this id */
    public ?string $variantFilter = null;

    /** @var string|null Only the input with this id */
    public ?string $inputFilter = null;

    /** @var bool[]|null The cell keys a child process is restricted to (key => true); null = no restriction (no child) */
    public ?array $restrictedCellKeySet = null;

    /**
     * Restricts the run to the cells `$cellKeys` (a child process).
     *
     * @param string[] $cellKeys
     * @return void
     */
    public function restrictToCellKeys(array $cellKeys): void
    {
        $this->restrictedCellKeySet = [];
        foreach ($cellKeys as $cellKey) {
            $this->restrictedCellKeySet[(string)$cellKey] = true;
        }
    }

    /** Whether the run is a child process restricted to the cells its parent gave it. */
    public function isChildRun(): bool
    {
        return $this->restrictedCellKeySet !== null;
    }

    /** Whether the cell with the key `$cellKey` belongs to this run. */
    public function allowsCellKey(string $cellKey): bool
    {
        return $this->restrictedCellKeySet === null || isset($this->restrictedCellKeySet[$cellKey]);
    }

    /**
     * The options a child process needs to build the same cells: `--guard-file`, `--pass-phrase`, `--input-text`,
     * `--input-class` (only with an ad-hoc input) and `--fixture`, each `--<name>=<value>`, in that order; an option
     * without a value is left out.
     *
     * @return string[]
     */
    public function buildChildOptionArguments(): array
    {
        $optionValuesByName = [
            'guard-file' => $this->guardFile,
            'pass-phrase' => $this->passPhraseOverride,
            'input-text' => $this->inputText,
            'input-class' => $this->inputText !== null ? $this->inputClass : null,
            'fixture' => $this->fixtureFile,
        ];
        $childOptionArguments = [];
        foreach ($optionValuesByName as $optionName => $optionValue) {
            if ($optionValue !== null && $optionValue !== '') {
                $childOptionArguments[] = '--' . $optionName . '=' . $optionValue;
            }
        }
        return $childOptionArguments;
    }
}
