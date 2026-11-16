<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The cells of one guard probe run ({@see AgentEvalGuardCell}), in the order of the fixture files, their variants and
 * their inputs. Read from guard fixtures:
 *
 * `{"suite": <name>, "passPhrase": <phrase>, "guardPromptVariants": [{"id", "systemPrompt"}, …], "inputs": [{"id",
 * "class": "benign"|"attack", "vector", "text"}, …]}` — the suite defaults to the file name; `{passPhrase}` in a system
 * prompt is replaced by the pass-phrase. A file without a pass-phrase, variants or inputs (after the run's knobs
 * replaced what they replace) gives no cell; so does a file that is no JSON object or list.
 *
 * Two cells with the same key both stay.
 *
 * @property AgentEvalGuardCell[] $elements
 * @method AgentEvalGuardCell[] getElements()
 * @method AgentEvalGuardCell|null first()
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardCells extends ObjectSet
{
    /** @var string The variant id of a candidate guard prompt that replaces the fixtures' variants */
    public const string CANDIDATE_VARIANT_ID = 'candidate';

    /** @var string The input id, and vector, of an ad-hoc input that replaces the fixtures' inputs */
    public const string AD_HOC_INPUT_ID = 'adhoc';

    /**
     * The cells of the fixture files `$fixturePaths` under the run's knobs: its replacements, its filters and the
     * restriction of a child process.
     *
     * @param string[] $fixturePaths
     * @param AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs
     * @return static
     */
    public static function buildOfFixtureFiles(array $fixturePaths, AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs): static
    {
        $agentEvalGuardCells = static::newInstance();
        foreach ($fixturePaths as $fixturePath) {
            $decodedFixture = json_decode((string)file_get_contents($fixturePath), true);
            if (is_array($decodedFixture)) {
                $agentEvalGuardCells->addCellsOfFixture((string)$fixturePath, $decodedFixture, $agentEvalGuardRunKnobs);
            }
        }
        return $agentEvalGuardCells;
    }

    /**
     * Adds the cells of one decoded fixture.
     *
     * @param string $fixturePath
     * @param array<int|string, mixed> $decodedFixture
     * @param AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs
     * @return void
     */
    public function addCellsOfFixture(string $fixturePath, array $decodedFixture, AgentEvalGuardRunKnobs $agentEvalGuardRunKnobs): void
    {
        $suite = (string)($decodedFixture['suite'] ?? basename($fixturePath, '.json'));
        $passPhrase = $agentEvalGuardRunKnobs->passPhraseOverride ?? (string)($decodedFixture['passPhrase'] ?? '');
        $decodedVariants = $agentEvalGuardRunKnobs->guardPromptText !== null
            ? [['id' => self::CANDIDATE_VARIANT_ID, 'systemPrompt' => $agentEvalGuardRunKnobs->guardPromptText]]
            : (is_array($decodedFixture['guardPromptVariants'] ?? null) ? $decodedFixture['guardPromptVariants'] : []);
        $decodedInputs = $agentEvalGuardRunKnobs->inputText !== null
            ? [['id' => self::AD_HOC_INPUT_ID, 'class' => $agentEvalGuardRunKnobs->inputClass, 'vector' => self::AD_HOC_INPUT_ID, 'text' => $agentEvalGuardRunKnobs->inputText]]
            : (is_array($decodedFixture['inputs'] ?? null) ? $decodedFixture['inputs'] : []);
        if ($passPhrase === '' || $decodedVariants === [] || $decodedInputs === []) {
            return;
        }
        foreach ($decodedVariants as $decodedVariant) {
            if (!is_array($decodedVariant)) {
                continue;
            }
            $variantId = (string)($decodedVariant['id'] ?? '');
            if ($agentEvalGuardRunKnobs->variantFilter !== null && $variantId !== $agentEvalGuardRunKnobs->variantFilter) {
                continue;
            }
            $systemPrompt = str_replace(AgentEvalGuardCell::PASS_PHRASE_PLACEHOLDER, $passPhrase, (string)($decodedVariant['systemPrompt'] ?? ''));
            foreach ($decodedInputs as $decodedInput) {
                if (!is_array($decodedInput)) {
                    continue;
                }
                $inputId = (string)($decodedInput['id'] ?? '');
                if ($agentEvalGuardRunKnobs->inputFilter !== null && $inputId !== $agentEvalGuardRunKnobs->inputFilter) {
                    continue;
                }
                $cellKey = AgentEvalGuardCell::buildKey($suite, $variantId, $inputId);
                if (!$agentEvalGuardRunKnobs->allowsCellKey($cellKey)) {
                    continue;
                }
                $agentEvalGuardCell = AgentEvalGuardCell::newInstance();
                $agentEvalGuardCell->key = $cellKey;
                $agentEvalGuardCell->suite = $suite;
                $agentEvalGuardCell->passPhrase = $passPhrase;
                $agentEvalGuardCell->variantId = $variantId;
                $agentEvalGuardCell->systemPrompt = $systemPrompt;
                $agentEvalGuardCell->inputId = $inputId;
                $agentEvalGuardCell->inputClass = (string)($decodedInput['class'] ?? AgentEvalGuardCell::INPUT_CLASS_ATTACK);
                $agentEvalGuardCell->vector = (string)($decodedInput['vector'] ?? '');
                $agentEvalGuardCell->text = (string)($decodedInput['text'] ?? '');
                $agentEvalGuardCell->runs = $agentEvalGuardRunKnobs->runsPerCell;
                $this->add($agentEvalGuardCell);
            }
        }
    }

    /**
     * The keys of the cells, in order.
     *
     * @return string[]
     */
    public function getCellKeys(): array
    {
        $cellKeys = [];
        foreach ($this->getElements() as $agentEvalGuardCell) {
            $cellKeys[] = $agentEvalGuardCell->key;
        }
        return $cellKeys;
    }
}
