<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Domain\Base\Entities\ValueObject;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * A decision fixture: the decoded JSON document the decision-fixtures command reads. The command reads its generic
 * keys through the typed readers (`site`, `cases`, `supportedLanguageCodes`, the agent-space key); an application's
 * own key (e.g. its own id of the entity the calls are billed to) through {@see getInteger()} or {@see getScalarText()}
 * — a decoded value is never handed out whole.
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentDecisionFixture extends ValueObject
{
    /** @var array<string, mixed> The decoded fixture document */
    protected array $fixtureFields = [];

    /**
     * The fixture of the JSON document `$fixtureJson`.
     *
     * @param string $fixtureJson
     * @return static
     * @throws JsonException when the document is no valid JSON
     */
    public static function fromJson(string $fixtureJson): static
    {
        $agentDecisionFixture = static::newInstance();
        $agentDecisionFixture->fixtureFields = (array)json_decode($fixtureJson, true, 512, JSON_THROW_ON_ERROR);
        return $agentDecisionFixture;
    }

    /** The value of `$key` as it was decoded; null when the fixture does not carry it. Read through the typed readers. */
    protected function getValue(string $key): mixed
    {
        return $this->fixtureFields[$key] ?? null;
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

    /** `site`: the decision site the cases ask; null when the fixture names none (the sub-skill preload pass). */
    public function getSite(): ?string
    {
        return isset($this->fixtureFields['site']) ? (string)$this->fixtureFields['site'] : null;
    }

    /** `cases`: every case object of the fixture, in order; empty when the fixture lists none. */
    public function getCases(): AgentDecisionFixtureCases
    {
        $agentDecisionFixtureCases = AgentDecisionFixtureCases::newInstance();
        $decodedCases = is_array($this->fixtureFields['cases'] ?? null) ? $this->fixtureFields['cases'] : [];
        foreach ($decodedCases as $decodedCase) {
            $agentDecisionFixtureCase = AgentDecisionFixtureCase::fromArray((array)$decodedCase);
            $agentDecisionFixtureCases->add($agentDecisionFixtureCase);
        }
        return $agentDecisionFixtureCases;
    }

    /**
     * `supportedLanguageCodes`: the languages a reply may take; `$defaultLanguageCodes` when the fixture names none.
     *
     * @param string[] $defaultLanguageCodes
     * @return string[]
     */
    public function getSupportedLanguageCodes(array $defaultLanguageCodes): array
    {
        return array_values(array_map('strval', (array)($this->fixtureFields['supportedLanguageCodes'] ?? $defaultLanguageCodes)));
    }
}
