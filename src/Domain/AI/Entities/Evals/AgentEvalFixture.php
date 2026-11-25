<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One fixture file of an evaluation suite: its path and its decoded JSON object. The generic keys of a fixture have
 * accessors here: `skill` (the skill the fixture's cases are about; the file name when it names none), `purposeName`
 * (the purpose every case of the fixture runs in unless the case names its own) and `cases` (the case objects,
 * {@see AgentEvalCase}). Every other key is an application's own: it is kept unchanged and read with
 * {@see getApplicationValue()}.
 *
 * The unique key of a fixture is its path, so a set holds one fixture per file.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalFixture extends ValueObject
{
    /** @var string The path of the fixture file */
    protected string $fixturePath = '';

    /** @var array<string, mixed> The fixture as it was decoded */
    protected array $fixtureFields = [];

    /**
     * The fixture of the file `$fixturePath`, decoded into `$fixtureFields`.
     *
     * @param string $fixturePath
     * @param array<string, mixed> $fixtureFields
     * @return static
     */
    public static function fromDecodedFile(string $fixturePath, array $fixtureFields): static
    {
        $agentEvalFixture = static::newInstance();
        $agentEvalFixture->fixturePath = $fixturePath;
        $agentEvalFixture->fixtureFields = $fixtureFields;
        return $agentEvalFixture;
    }

    /** The path of the fixture file. */
    public function getPath(): string
    {
        return $this->fixturePath;
    }

    /** The file name without the `.json` suffix: the key of the fixture's resource group and of its exclusivity. */
    public function getName(): string
    {
        return basename($this->fixturePath, '.json');
    }

    /** Whether the fixture names its skill in a `skill` field (not only by its file name). */
    public function declaresSkillName(): bool
    {
        return trim((string)($this->fixtureFields['skill'] ?? '')) !== '';
    }

    /** `skill`: the skill the fixture's cases are about; the file name when the fixture names none. */
    public function getSkillName(): string
    {
        return (string)($this->fixtureFields['skill'] ?? $this->getName());
    }

    /** `purposeName`: the purpose of the fixture's cases, trimmed; null when the fixture names none. */
    public function getPurposeName(): ?string
    {
        $purposeName = trim((string)($this->fixtureFields['purposeName'] ?? ''));
        return $purposeName !== '' ? $purposeName : null;
    }

    /** Whether the fixture carries a `cases` list. */
    public function hasCases(): bool
    {
        return is_array($this->fixtureFields['cases'] ?? null);
    }

    /**
     * `cases`: every entry of the list that is a JSON object with a non-empty `id`, in the order of the file.
     */
    public function getCases(): AgentEvalCases
    {
        $agentEvalCases = AgentEvalCases::newInstance();
        foreach ((array)($this->fixtureFields['cases'] ?? []) as $caseFields) {
            if (!is_array($caseFields) || (string)($caseFields['id'] ?? '') === '') {
                continue;
            }
            $agentEvalCase = AgentEvalCase::fromArray($caseFields);
            $agentEvalCases->add($agentEvalCase);
        }
        return $agentEvalCases;
    }

    /**
     * The purpose a case runs in: the case's own `purposeName`, else the fixture's, trimmed; null for an open
     * conversation.
     */
    public function resolvePurposeNameOfCase(AgentEvalCase $agentEvalCase): ?string
    {
        $casePurposeName = $agentEvalCase->getPurposeName();
        return $casePurposeName !== null ? trim($casePurposeName) : $this->getPurposeName();
    }

    /**
     * The value of a key of the fixture as it was decoded — for an application's own key. Null when the fixture does
     * not carry the key.
     */
    public function getApplicationValue(string $key): mixed
    {
        return $this->fixtureFields[$key] ?? null;
    }

    /**
     * The fixture exactly as it was decoded.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->fixtureFields;
    }

    /** The fixture's path. */
    public function uniqueKey(): string
    {
        return self::class . '_' . $this->fixturePath;
    }
}
