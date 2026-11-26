<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Goals\Blueprints;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * One node of a standard goal tree ({@see AgentGoalBlueprint}): what a business of this kind should have, as the
 * application knows it. A node is a template, never a stored goal: the agent adopts it with `addGoal` and the node's
 * {@see $key} as the goal's blueprint key.
 *
 * An orienting node (an area, a vision) has no done criterion; a leaf names one ({@see $doneCriterionKey},
 * {@see $doneCriterionDescription}, {@see $criterionType}), and the application's assessment says where the space
 * stands on it ({@see $assessment}, {@see $assessmentDetail}). The package holds no content: the application builds,
 * prunes and assesses the tree ({@see \DDD\Domain\AI\Services\Conversations\Goals\AgentGoalBlueprintProviderService}).
 */
class AgentGoalBlueprintNode extends ValueObject implements LlmMarkdownRenderable
{
    /** A leaf reached once and then held ("the opening hours are complete"). */
    public const string CRITERION_TYPE_STATE = 'STATE';

    /** A leaf that holds only while a rhythm is kept ("every review answered within two days"). */
    public const string CRITERION_TYPE_CADENCE = 'CADENCE';

    /** An outcome measured by a level or a trend, not a checkbox ("the rating rises"). */
    public const string CRITERION_TYPE_TREND = 'TREND';

    /** @var string[] Every criterion type */
    public const array CRITERION_TYPES = [self::CRITERION_TYPE_STATE, self::CRITERION_TYPE_CADENCE, self::CRITERION_TYPE_TREND];

    /** The node is live: its criterion can be measured and the gap acted on. */
    public const string STATUS_ACTIVE = 'ACTIVE';

    /** The node holds a place in the tree; it cannot be measured yet. */
    public const string STATUS_DEFERRED = 'DEFERRED';

    /** @var string[] Every node status */
    public const array STATUSES = [self::STATUS_ACTIVE, self::STATUS_DEFERRED];

    /** The criterion is met. */
    public const string ASSESSMENT_MET = 'MET';

    /** The criterion is measured and not met: work. */
    public const string ASSESSMENT_OPEN = 'OPEN';

    /** The criterion could not be measured; never treated as met. */
    public const string ASSESSMENT_UNKNOWN = 'UNKNOWN';

    /** @var string[] Every assessment */
    public const array ASSESSMENTS = [self::ASSESSMENT_MET, self::ASSESSMENT_OPEN, self::ASSESSMENT_UNKNOWN];

    /** @var string The stable key of the node; the blueprint key of a goal adopted from it */
    public string $key;

    /** @var string|null The key of the parent node; empty = a root */
    public ?string $parentKey = null;

    /** @var string The goal in generic words (the agent restates it to the owner) */
    public string $statement;

    /** @var string Who holds an adopted goal: OWNER or AGENT */
    #[Choice(choices: AgentGoal::HOLDERS)]
    public string $holder = AgentGoal::HOLDER_AGENT;

    /** @var string VISION for an orienting node, LONG_TERM or SHORT_TERM for an actionable leaf */
    #[Choice(choices: AgentGoal::HORIZONS)]
    public string $horizonLevel = AgentGoal::HORIZON_VISION;

    /** @var string|null The key of the criterion the application measures; empty for an orienting node */
    public ?string $doneCriterionKey = null;

    /** @var string What "done" means, in one line (shown to the model) */
    public string $doneCriterionDescription = '';

    /** @var string|null STATE, CADENCE or TREND; empty for an orienting node */
    #[Choice(choices: self::CRITERION_TYPES)]
    public ?string $criterionType = null;

    /** @var int The default weight; higher matters more */
    public int $weight = 0;

    /** @var string ACTIVE (measurable now) or DEFERRED (a place in the tree only) */
    #[Choice(choices: self::STATUSES)]
    public string $status = self::STATUS_ACTIVE;

    /** @var string|null Where the space stands on the criterion: MET, OPEN or UNKNOWN; empty = not assessed */
    #[Choice(choices: self::ASSESSMENTS)]
    public ?string $assessment = null;

    /** @var string|null One line of the assessment ("3 of 5", "last post 40 days ago") */
    public ?string $assessmentDetail = null;

    /**
     * A node: the key and the statement, the parent key (empty for a root), and for a leaf its done criterion.
     *
     * @param string $key
     * @param string $statement
     * @param string|null $parentKey
     * @param string|null $doneCriterionKey
     * @param string $doneCriterionDescription
     * @param string|null $criterionType
     * @param int $weight
     * @param string $status
     * @param string $holder
     * @param string $horizonLevel
     * @return static
     */
    public static function define(
        string $key,
        string $statement,
        ?string $parentKey = null,
        ?string $doneCriterionKey = null,
        string $doneCriterionDescription = '',
        ?string $criterionType = null,
        int $weight = 0,
        string $status = self::STATUS_ACTIVE,
        string $holder = AgentGoal::HOLDER_AGENT,
        string $horizonLevel = AgentGoal::HORIZON_VISION
    ): static {
        $agentGoalBlueprintNode = new static();
        $agentGoalBlueprintNode->key = $key;
        $agentGoalBlueprintNode->statement = $statement;
        $agentGoalBlueprintNode->parentKey = $parentKey;
        $agentGoalBlueprintNode->doneCriterionKey = $doneCriterionKey;
        $agentGoalBlueprintNode->doneCriterionDescription = $doneCriterionDescription;
        $agentGoalBlueprintNode->criterionType = $criterionType;
        $agentGoalBlueprintNode->weight = $weight;
        $agentGoalBlueprintNode->status = $status;
        $agentGoalBlueprintNode->holder = $holder;
        $agentGoalBlueprintNode->horizonLevel = $horizonLevel;
        return $agentGoalBlueprintNode;
    }

    /**
     * The unique key of the node with the key `$nodeKey` in an {@see AgentGoalBlueprintNodes} set (the declaring class
     * and the node key, so a lookup finds a node of a subclass as well).
     *
     * @param string $nodeKey
     * @return string
     */
    public static function buildUniqueKeyOfNodeKey(string $nodeKey): string
    {
        return self::class . '_' . $nodeKey;
    }

    public function uniqueKey(): string
    {
        return static::buildUniqueKeyOfNodeKey($this->key);
    }

    /**
     * Records where the space stands on the node's criterion.
     *
     * @param string $assessment MET, OPEN or UNKNOWN
     * @param string|null $assessmentDetail
     * @return void
     */
    public function assess(string $assessment, ?string $assessmentDetail = null): void
    {
        $this->assessment = $assessment;
        $this->assessmentDetail = $assessmentDetail !== null && trim($assessmentDetail) !== '' ? trim($assessmentDetail) : null;
    }

    /**
     * Whether the node names a done criterion (a leaf), as opposed to an orienting node.
     *
     * @return bool
     */
    public function isLeaf(): bool
    {
        return $this->doneCriterionKey !== null && $this->doneCriterionKey !== '';
    }

    /**
     * One line: the assessment marker (✅ met, ⬜ open, ❔ unknown; `·` an unassessed leaf, `▸` an area), the key in
     * backticks (the exact string `addGoal` takes as `blueprintKey`), the statement, and for a leaf its criterion and
     * the assessment detail; a deferred node says it cannot be measured yet.
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $marker = match ($this->assessment) {
            self::ASSESSMENT_MET => '✅',
            self::ASSESSMENT_OPEN => '⬜',
            self::ASSESSMENT_UNKNOWN => '❔',
            default => $this->isLeaf() ? '·' : '▸',
        };
        $line = $marker . ' `' . $this->key . '` ' . $this->statement;
        if ($this->status === self::STATUS_DEFERRED) {
            return $line . ' _(not yet measurable)_';
        }
        if ($this->isLeaf()) {
            $line .= ' — ' . $this->doneCriterionDescription;
            if ($this->assessmentDetail !== null) {
                $line .= ' [' . $this->assessmentDetail . ']';
            }
        }
        return $line;
    }
}
