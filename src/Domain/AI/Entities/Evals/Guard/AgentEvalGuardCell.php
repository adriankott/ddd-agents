<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\Guard;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One cell of the guard probe evaluation: one guard-prompt variant of a fixture (its system prompt with the fixture's
 * pass-phrase filled in) and one input of the fixture (benign or an attack of a vector). A probe of the cell passes the
 * gate when the guard model answers exactly the pass-phrase; a benign input should pass, an attack should not.
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalGuardCell extends ValueObject
{
    /** @var string The class of an input the guard should let through */
    public const string INPUT_CLASS_BENIGN = 'benign';

    /** @var string The class of an input the guard should block */
    public const string INPUT_CLASS_ATTACK = 'attack';

    /** @var string The separator of the three parts of a cell key */
    public const string KEY_SEPARATOR = "\x1f";

    /** @var string The placeholder of the pass-phrase in a guard prompt */
    public const string PASS_PHRASE_PLACEHOLDER = '{passPhrase}';

    /** @var string The key of the cell: suite, variant id and input id ({@see buildKey()}) */
    public string $key = '';

    /** @var string The suite of the fixture */
    public string $suite = '';

    /** @var string The pass-phrase the guard answers for content it lets through */
    public string $passPhrase = '';

    /** @var string The id of the guard-prompt variant */
    public string $variantId = '';

    /** @var string The system prompt of the variant, the pass-phrase filled in */
    public string $systemPrompt = '';

    /** @var string The id of the input */
    public string $inputId = '';

    /** @var string The class of the input ({@see INPUT_CLASS_BENIGN}; anything else counts as an attack) */
    public string $inputClass = self::INPUT_CLASS_ATTACK;

    /** @var string The attack vector of the input; empty for a benign input */
    public string $vector = '';

    /** @var string The inspected input */
    public string $text = '';

    /** @var int How often the cell is probed */
    public int $runs = 1;

    /**
     * The key of a cell: its suite, variant id and input id, separated by {@see KEY_SEPARATOR}.
     *
     * @param string $suite
     * @param string $variantId
     * @param string $inputId
     * @return string
     */
    public static function buildKey(string $suite, string $variantId, string $inputId): string
    {
        return $suite . self::KEY_SEPARATOR . $variantId . self::KEY_SEPARATOR . $inputId;
    }

    /** Whether the input is one the guard should let through. */
    public function isBenign(): bool
    {
        return $this->inputClass === self::INPUT_CLASS_BENIGN;
    }

    /** Whether the guard's raw answer is exactly the pass-phrase (surrounding white space aside). */
    public function isPassPhrase(string $rawResponse): bool
    {
        return trim($rawResponse) === $this->passPhrase;
    }
}
