<?php

declare(strict_types=1);

namespace DDD\Symfony\Commands\Agents\Eval;

use DDD\Domain\Base\Entities\ValueObject;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The compaction parameters of one compaction-tree evaluation: how often the conversation compacts and how the tree
 * rolls up, and how many owner turns the driver keeps the conversation going. The driver and the judge read them as a
 * text built from the case's template ({@see buildParametersText()}); the conversation gets them as its compaction
 * settings ({@see buildCompactionSettings()}).
 *
 * A plain object of one command run; no container service.
 *
 * @phpstan-consistent-constructor
 */
#[Exclude]
class AgentEvalCompactionTreeParameters extends ValueObject
{
    /** @var int Messages per level-0 leaf (a compaction roughly every N messages) */
    public int $segmentMessages = 0;

    /** @var int Recent messages kept raw */
    public int $keepRecentMessages = 0;

    /** @var int Roll-up fan-in K */
    public int $tierFanIn = 0;

    /** @var int Nodes kept per level */
    public int $levelRetention = 0;

    /** @var int Absolute compaction trigger in tokens */
    public int $triggerTokens = 0;

    /** @var int Owner turns the driver keeps the conversation going */
    public int $maxTurns = 0;

    /**
     * The parameters of a run.
     *
     * @param int $segmentMessages
     * @param int $keepRecentMessages
     * @param int $tierFanIn
     * @param int $levelRetention
     * @param int $triggerTokens
     * @param int $maxTurns
     * @return static
     */
    public static function forRun(int $segmentMessages, int $keepRecentMessages, int $tierFanIn, int $levelRetention, int $triggerTokens, int $maxTurns): static
    {
        $agentEvalCompactionTreeParameters = static::newInstance();
        $agentEvalCompactionTreeParameters->segmentMessages = $segmentMessages;
        $agentEvalCompactionTreeParameters->keepRecentMessages = $keepRecentMessages;
        $agentEvalCompactionTreeParameters->tierFanIn = $tierFanIn;
        $agentEvalCompactionTreeParameters->levelRetention = $levelRetention;
        $agentEvalCompactionTreeParameters->triggerTokens = $triggerTokens;
        $agentEvalCompactionTreeParameters->maxTurns = $maxTurns;
        return $agentEvalCompactionTreeParameters;
    }

    /**
     * The value of every parameter by its placeholder name, in the order of the conversation's compaction settings,
     * then `maxTurns`.
     *
     * @return int[]
     */
    public function getValuesByPlaceholderName(): array
    {
        return $this->buildCompactionSettings() + ['maxTurns' => $this->maxTurns];
    }

    /**
     * The conversation's compaction settings (`compactionSettings` of the judged case), by setting name.
     *
     * @return int[]
     */
    public function buildCompactionSettings(): array
    {
        return [
            'segmentMessages' => $this->segmentMessages,
            'keepRecentMessages' => $this->keepRecentMessages,
            'tierFanIn' => $this->tierFanIn,
            'levelRetention' => $this->levelRetention,
            'triggerTokens' => $this->triggerTokens,
        ];
    }

    /**
     * `$parametersTextTemplate` with every `{{<name>}}` of {@see getValuesByPlaceholderName()} replaced by its value.
     *
     * @param string $parametersTextTemplate
     * @return string
     */
    public function buildParametersText(string $parametersTextTemplate): string
    {
        $parametersText = $parametersTextTemplate;
        foreach ($this->getValuesByPlaceholderName() as $placeholderName => $parameterValue) {
            $parametersText = str_replace('{{' . $placeholderName . '}}', (string)$parameterValue, $parametersText);
        }
        return $parametersText;
    }
}
