<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Goals\Blueprints;

use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * A standard goal tree: what a business of this kind should have, built by the application for one space, pruned to
 * what applies there and assessed against the space's data
 * ({@see \DDD\Domain\AI\Services\Conversations\Goals\AgentGoalBlueprintProviderService}). The agent builds the owner's
 * own tree from it: it adopts open nodes as goals (`addGoal` with the node's key as `blueprintKey`) and never invents a
 * standard goal. The package ships no tree.
 */
class AgentGoalBlueprint extends ValueObject implements LlmMarkdownRenderable
{
    /** @var string The first line of the rendered tree */
    public const string HEADER = '## Standard goal tree (current state vs target)';

    /** @var string The marker of a root line */
    public const string ROOT_MARKER = '🎯';

    /** @var AgentGoalBlueprintNodes|null The nodes, parents before or after their children, in the order to render siblings */
    public ?AgentGoalBlueprintNodes $nodes = null;

    /**
     * Adds a node; a node whose key is in the tree already is not added.
     *
     * @param AgentGoalBlueprintNode $agentGoalBlueprintNode
     * @return void
     */
    public function addNode(AgentGoalBlueprintNode $agentGoalBlueprintNode): void
    {
        if ($this->nodes === null) {
            $this->nodes = new AgentGoalBlueprintNodes();
        }
        $this->nodes->add($agentGoalBlueprintNode);
    }

    /**
     * The node with the key `$nodeKey`, or null.
     *
     * @param string $nodeKey
     * @return AgentGoalBlueprintNode|null
     */
    public function findNodeByKey(string $nodeKey): ?AgentGoalBlueprintNode
    {
        return $this->nodes?->findNodeByKey($nodeKey);
    }

    /**
     * The tree, indented two spaces per level: the header, each root as `🎯 <statement>`, every node below it as its
     * own line ({@see AgentGoalBlueprintNode::toLlmMarkdown()}). A node is rendered once, also when the parent keys
     * form a loop.
     *
     * @return string
     */
    public function toLlmMarkdown(): string
    {
        $lines = [self::HEADER];
        $renderedNodeKeys = [];
        foreach ($this->nodes?->getRootNodes()->getElements() ?? [] as $rootNode) {
            $renderedNodeKeys[$rootNode->key] = true;
            $lines[] = self::ROOT_MARKER . ' ' . $rootNode->statement;
            $this->renderChildren($rootNode->key, 1, $lines, $renderedNodeKeys);
        }
        return implode("\n", $lines);
    }

    /**
     * Appends the lines of the children of `$parentKey` and of their children, `$depth` levels deep.
     *
     * @param string $parentKey
     * @param int $depth
     * @param string[] $lines
     * @param array<string, bool> $renderedNodeKeys
     * @return void
     */
    protected function renderChildren(string $parentKey, int $depth, array &$lines, array &$renderedNodeKeys): void
    {
        foreach ($this->nodes?->getChildrenOf($parentKey)->getElements() ?? [] as $childNode) {
            if (isset($renderedNodeKeys[$childNode->key])) {
                continue;
            }
            $renderedNodeKeys[$childNode->key] = true;
            $lines[] = str_repeat('  ', $depth) . $childNode->toLlmMarkdown();
            $this->renderChildren($childNode->key, $depth + 1, $lines, $renderedNodeKeys);
        }
    }
}
