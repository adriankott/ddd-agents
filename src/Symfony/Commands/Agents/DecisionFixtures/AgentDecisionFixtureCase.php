<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One case of a decision fixture, built from the decoded JSON object of the case. The command reads the keys of its
 * site through the typed readers; every key stays as it was decoded.
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionFixtureCase extends ValueObject
{
    /** @var string The id of a case that names none */
    public const string ID_OF_A_CASE_WITHOUT_ONE = '?';

    /** @var array<string, mixed> The case as it was decoded */
    protected array $caseFields = [];

    /**
     * The case of the decoded fixture object `$caseFields`.
     *
     * @param array<string, mixed> $caseFields
     * @return static
     */
    public static function fromArray(array $caseFields): static
    {
        $agentDecisionFixtureCase = static::newInstance();
        $agentDecisionFixtureCase->caseFields = $caseFields;
        return $agentDecisionFixtureCase;
    }

    /** `id`: the id of the case; {@see ID_OF_A_CASE_WITHOUT_ONE} when it names none. */
    public function getId(): string
    {
        return (string)($this->caseFields['id'] ?? self::ID_OF_A_CASE_WITHOUT_ONE);
    }

    /** The value of `$key` as text; empty when the case does not carry it. */
    public function getText(string $key): string
    {
        return (string)($this->caseFields[$key] ?? '');
    }

    /** The value of `$key` as text, or null when the case does not carry it or carries null. */
    public function getNullableText(string $key): ?string
    {
        return isset($this->caseFields[$key]) ? (string)$this->caseFields[$key] : null;
    }

    /**
     * The value of `$key` as a list of texts; empty when the case does not carry it.
     *
     * @param string $key
     * @return string[]
     */
    public function getTexts(string $key): array
    {
        return array_values(array_map('strval', (array)($this->caseFields[$key] ?? [])));
    }

    /** The value of `$key` as it was decoded; null when the case does not carry it. Read through the typed readers. */
    protected function getValue(string $key): mixed
    {
        return $this->caseFields[$key] ?? null;
    }
}
