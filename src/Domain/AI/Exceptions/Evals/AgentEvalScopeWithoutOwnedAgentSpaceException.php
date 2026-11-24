<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Evals;

use RuntimeException;

/**
 * The space-only scope of an evaluation case found no agent space with a known owner: the case and the caller name no
 * space, or the named space has no owner. A run that names an account (`--accountId`) then runs at account level or
 * for that account ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::resolveEvalScopeWithFallbackAccount()});
 * any other failure of the scope hook stops the case.
 */
class AgentEvalScopeWithoutOwnedAgentSpaceException extends RuntimeException
{
}
