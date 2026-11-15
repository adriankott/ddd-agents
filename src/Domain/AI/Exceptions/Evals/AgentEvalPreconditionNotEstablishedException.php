<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Exceptions\Evals;

use RuntimeException;

/**
 * A preparation step of an evaluation case (`restore.setup`) answered an error, or did not answer what the case names
 * as its premise, so the state the case is about does not exist: the record to change was not created, the setting to
 * switch was not put to its start value. The agent is not run — what it does without that state says nothing about
 * the case — and the case counts as not applicable, neither a pass nor a fail
 * ({@see \DDD\Domain\AI\Entities\Evals\AgentEvalNotApplicableReason::buildPreconditionNotEstablishedReason()}).
 *
 * The module throws this class itself (never a subclass), so a caller that turns it into a not-applicable case catches
 * this class.
 */
class AgentEvalPreconditionNotEstablishedException extends RuntimeException
{
}
