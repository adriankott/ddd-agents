<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The parameters of the route the owner's client shows, in the order the client sent them
 * ({@see AgentClientContext::$routeParameters}). On the wire a list of `{name, value}` objects.
 *
 * @property AgentClientContextRouteParameter[] $elements
 * @method AgentClientContextRouteParameter|null first()
 * @method AgentClientContextRouteParameter[] getElements()
 * @method AgentClientContextRouteParameter|null getByUniqueKey(string $uniqueKey)
 */
class AgentClientContextRouteParameters extends ObjectSet
{
    /** `name=value&name=value`: the text the size cap counts and the default rendering shows. */
    public function toQueryText(): string
    {
        $assignmentTexts = [];
        foreach ($this->getElements() as $agentClientContextRouteParameter) {
            $assignmentTexts[] = $agentClientContextRouteParameter->toAssignmentText();
        }
        return implode('&', $assignmentTexts);
    }
}
