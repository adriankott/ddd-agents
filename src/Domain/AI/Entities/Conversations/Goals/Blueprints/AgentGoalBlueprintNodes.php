<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Goals\Blueprints;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The nodes of a standard goal tree, in the order the application added them. Keyed by the node key
 * ({@see AgentGoalBlueprintNode::uniqueKey()}): a second node with a key already in the set is not added.
 *
 * @property AgentGoalBlueprintNode[] $elements
 * @method AgentGoalBlueprintNode|null first()
 * @method AgentGoalBlueprintNode[] getElements()
 */
class AgentGoalBlueprintNodes extends ObjectSet
{
    /**
     * The node with the key `$nodeKey`, or null.
     *
     * @param string $nodeKey
     * @return AgentGoalBlueprintNode|null
     */
    public function findNodeByKey(string $nodeKey): ?AgentGoalBlueprintNode
    {
        $agentGoalBlueprintNode = $this->getByUniqueKey(AgentGoalBlueprintNode::buildUniqueKeyOfNodeKey($nodeKey));
        return $agentGoalBlueprintNode instanceof AgentGoalBlueprintNode ? $agentGoalBlueprintNode : null;
    }

    /**
     * The direct children of the node `$parentKey`, in the set's order.
     *
     * @param string $parentKey
     * @return AgentGoalBlueprintNodes
     */
    public function getChildrenOf(string $parentKey): AgentGoalBlueprintNodes
    {
        $childNodes = new AgentGoalBlueprintNodes();
        foreach ($this->getElements() as $agentGoalBlueprintNode) {
            if ($agentGoalBlueprintNode->parentKey === $parentKey) {
                $childNodes->add($agentGoalBlueprintNode);
            }
        }
        return $childNodes;
    }

    /**
     * The roots: the nodes without a parent key, and the nodes whose parent is not in the set (a pruned parent), in the
     * set's order.
     *
     * @return AgentGoalBlueprintNodes
     */
    public function getRootNodes(): AgentGoalBlueprintNodes
    {
        $rootNodes = new AgentGoalBlueprintNodes();
        foreach ($this->getElements() as $agentGoalBlueprintNode) {
            $parentKey = $agentGoalBlueprintNode->parentKey;
            if ($parentKey === null || $parentKey === '' || $this->findNodeByKey($parentKey) === null) {
                $rootNodes->add($agentGoalBlueprintNode);
            }
        }
        return $rootNodes;
    }
}
