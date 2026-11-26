<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprint;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * The answer of `getGoalBlueprint`: the standard goal tree of the space, assessed, with the legend of its markers and a
 * note on how to work with it.
 */
class AgentGoalBlueprintMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    public const string LEGEND = 'Legend: ✅ done · ⬜ open (= work) · ❔ not yet measured · ▸ area · · standard goal.';

    public const string NO_BLUEPRINT_LINE = '_No standard goal tree for this space._';

    public const string NOTE_HOW_TO_USE = 'The ⬜ open goals are the agenda. Add the owner\'s own goals with addGoal under the fitting area; propose the most valuable open nodes and add the adopted ones with addGoal (blueprintKey = the node\'s key, statedByOwner false). Standard goals come only from this tree — never invent one.';

    /** @var AgentGoalBlueprint|null The standard goal tree, pruned to the space and assessed */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AgentGoalBlueprint $goalBlueprint = null;

    /** @var string|null How to work with the tree */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    /**
     * The answer for the tree the application built (null: it has none for this space).
     *
     * @param AgentGoalBlueprint|null $agentGoalBlueprint
     * @return static
     */
    public static function forBlueprint(?AgentGoalBlueprint $agentGoalBlueprint): static
    {
        $agentGoalBlueprintMcpResponseDto = new static();
        $agentGoalBlueprintMcpResponseDto->goalBlueprint = $agentGoalBlueprint;
        $agentGoalBlueprintMcpResponseDto->note = $agentGoalBlueprint !== null ? self::NOTE_HOW_TO_USE : null;
        return $agentGoalBlueprintMcpResponseDto;
    }

    public function toLlmMarkdown(): string
    {
        if ($this->goalBlueprint === null) {
            return self::NO_BLUEPRINT_LINE;
        }
        $lines = [$this->goalBlueprint->toLlmMarkdown(), '', self::LEGEND];
        if ($this->note !== null && $this->note !== '') {
            $lines[] = '';
            $lines[] = $this->note;
        }
        return implode("\n", $lines);
    }
}
