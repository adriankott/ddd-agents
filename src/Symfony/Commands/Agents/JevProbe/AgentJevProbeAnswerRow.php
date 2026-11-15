<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\JevProbe;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentProbeDecisionAnswer;
use JsonSerializable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * One answer of the decision probe: a question of one case, the answer the model gave (none when the call failed),
 * the label the fixture expects and whether the answer hits it. Written to the result file with the keys `case`,
 * `question`, `answer`, `expected`, `hit` and `failure`; the score key is the probe's own bookkeeping.
 *
 * A plain object of one probe run; no container service.
 */
#[Exclude]
class AgentJevProbeAnswerRow implements JsonSerializable
{
    /**
     * @param string $caseId the case's `id` (`?` without one)
     * @param int|string $questionName the question's name in the case
     * @param AgentProbeDecisionAnswer|null $answer null = no answer for the question
     * @param mixed $expected the case's label for the question (`true`, `false` or a choice); null = unlabelled
     * @param bool|null $hit null = not scored (no label or no answer)
     * @param string|null $failure why the case's call gave no usable answer set
     * @param string $scoreKey the key the hit rate is counted under (the case's `scoreAs`, else the question)
     */
    public function __construct(
        public readonly string $caseId,
        public readonly int|string $questionName,
        public readonly ?AgentProbeDecisionAnswer $answer,
        public readonly mixed $expected,
        public readonly ?bool $hit,
        public readonly ?string $failure,
        public readonly string $scoreKey,
    ) {
    }

    /**
     * The text of the answer column: the model's answer, or `ERR` with the start of the failure.
     *
     * @return string
     */
    public function getPrintedAnswerText(): string
    {
        return $this->answer === null ? ('ERR ' . mb_substr((string)$this->failure, 0, 24)) : $this->answer->toPrintedText();
    }

    /**
     * The row as the result file writes it.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'case' => $this->caseId,
            'question' => $this->questionName,
            'answer' => $this->answer,
            'expected' => $this->expected,
            'hit' => $this->hit,
            'failure' => $this->failure,
        ];
    }
}
