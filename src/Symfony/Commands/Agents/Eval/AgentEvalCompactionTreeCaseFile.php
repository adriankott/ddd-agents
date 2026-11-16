<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\AI\Entities\Evals\AgentEvalCase;
use DDD\Domain\AI\Entities\Evals\AgentEvalSimCase;
use DDD\Domain\Base\Entities\ValueObject;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The case of a compaction-tree evaluation, read from its JSON case file:
 *   {"id": "...", "agentSpaceId": 7, "goal": "...", "openingPrompt": "...", "userFacts": "...",
 *    "successRubric": "...", "compactionParamsTemplate": "segmentMessages={{segmentMessages}} ..."}
 * `goal` and `openingPrompt` are required. The texts are model input: the driver plays the owner from `goal`,
 * `openingPrompt` and `userFacts`, the judge rates against `successRubric`, and both read the compaction parameters as
 * the text of `compactionParamsTemplate` (placeholders `{{segmentMessages}}`, `{{keepRecentMessages}}`,
 * `{{tierFanIn}}`, `{{levelRetention}}`, `{{triggerTokens}}`, `{{maxTurns}}`; the command's default template when the
 * file has none). `agentSpaceId` names the space the run works in when the command's options name none.
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalCompactionTreeCaseFile extends ValueObject
{
    /** @var string The id of a case file that names none */
    public const string DEFAULT_CASE_ID = 'compaction-tree';

    /** @var string[] The keys a case file must carry with a non-empty text */
    public const array REQUIRED_TEXT_KEYS = ['goal', 'openingPrompt'];

    /** @var string[] The judged-case texts taken over from the case file when it carries them, in this order */
    public const array JUDGED_CASE_TEXT_KEYS = ['goal', 'openingPrompt', 'userFacts', 'successRubric'];

    /** @var string The key of the template of the compaction parameters text */
    public const string KEY_COMPACTION_PARAMS_TEMPLATE = 'compactionParamsTemplate';

    /** @var array<string, mixed> The decoded case file */
    protected array $caseFields = [];

    /**
     * The case of the JSON document `$caseFileJson`.
     *
     * @param string $caseFileJson
     * @return static
     * @throws JsonException when the document is no valid JSON
     */
    public static function fromJson(string $caseFileJson): static
    {
        $agentEvalCompactionTreeCaseFile = static::newInstance();
        $agentEvalCompactionTreeCaseFile->caseFields = (array)json_decode($caseFileJson, true, 512, JSON_THROW_ON_ERROR);
        return $agentEvalCompactionTreeCaseFile;
    }

    /**
     * The required keys the case file lacks or leaves empty, in the order of {@see REQUIRED_TEXT_KEYS}.
     *
     * @return string[]
     */
    public function findMissingRequiredKeys(): array
    {
        $missingRequiredKeys = [];
        foreach (static::REQUIRED_TEXT_KEYS as $requiredKey) {
            if (trim((string)($this->caseFields[$requiredKey] ?? '')) === '') {
                $missingRequiredKeys[] = $requiredKey;
            }
        }
        return $missingRequiredKeys;
    }

    /** Whether the case file names the space the run works in. */
    public function namesAgentSpace(): bool
    {
        return (int)($this->caseFields[AgentEvalCase::KEY_AGENT_SPACE_ID] ?? 0) > 0;
    }

    /** The value of `$key` as it was decoded; null when the file does not carry it. Read through the typed readers. */
    protected function getValue(string $key): mixed
    {
        return $this->caseFields[$key] ?? null;
    }

    /** The value of `$key` as a whole number: a decoded integer or a numeric text; null for any other value or none. */
    public function getInteger(string $key): ?int
    {
        $value = $this->getValue($key);
        return is_int($value) || (is_string($value) && is_numeric($value)) ? (int)$value : null;
    }

    /** The value of `$key` as text: a decoded text or number; null for any other value or none. */
    public function getScalarText(string $key): ?string
    {
        $value = $this->getValue($key);
        return is_string($value) || is_int($value) || is_float($value) ? (string)$value : null;
    }

    /** The template of the compaction parameters text: the file's own, else `$defaultTemplate`. */
    public function getCompactionParamsTemplate(string $defaultTemplate): string
    {
        $compactionParamsTemplate = (string)($this->caseFields[self::KEY_COMPACTION_PARAMS_TEMPLATE] ?? '');
        return $compactionParamsTemplate !== '' ? $compactionParamsTemplate : $defaultTemplate;
    }

    /**
     * The evaluation case the scope of the run is resolved from: the file's `id` (else {@see DEFAULT_CASE_ID}) and its
     * `agentSpaceId` when it names one.
     *
     * @return AgentEvalCase
     */
    public function buildEvalCase(): AgentEvalCase
    {
        $evalCaseFields = ['id' => (string)($this->caseFields['id'] ?? self::DEFAULT_CASE_ID)];
        if ($this->namesAgentSpace()) {
            $evalCaseFields[AgentEvalCase::KEY_AGENT_SPACE_ID] = (int)$this->caseFields[AgentEvalCase::KEY_AGENT_SPACE_ID];
        }
        return AgentEvalCase::fromArray($evalCaseFields);
    }

    /**
     * The judged case of the run: the file's texts ({@see JUDGED_CASE_TEXT_KEYS}, each one the file carries), then
     * `compactionParamsText`, `maxTurns` and `compactionSettings` of `$agentEvalCompactionTreeParameters`.
     *
     * @param AgentEvalCompactionTreeParameters $agentEvalCompactionTreeParameters
     * @param string $compactionParamsText
     * @return AgentEvalSimCase
     */
    public function buildSimCase(AgentEvalCompactionTreeParameters $agentEvalCompactionTreeParameters, string $compactionParamsText): AgentEvalSimCase
    {
        $simCaseFields = [];
        foreach (static::JUDGED_CASE_TEXT_KEYS as $judgedCaseTextKey) {
            if (array_key_exists($judgedCaseTextKey, $this->caseFields)) {
                $simCaseFields[$judgedCaseTextKey] = (string)$this->caseFields[$judgedCaseTextKey];
            }
        }
        $simCaseFields['compactionParamsText'] = $compactionParamsText;
        $simCaseFields['maxTurns'] = $agentEvalCompactionTreeParameters->maxTurns;
        $simCaseFields['compactionSettings'] = $agentEvalCompactionTreeParameters->buildCompactionSettings();
        return AgentEvalSimCase::fromArray($simCaseFields);
    }
}
