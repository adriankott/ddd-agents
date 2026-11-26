<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method as an IRREVERSIBLE/DESTRUCTIVE owner action that the agent loop may only execute with the
 * OWNER'S OWN CONSENT to exactly this step. The tool asks
 * {@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::requireOwnerConsentForStep()} after its free
 * domain refusals and before its write: the decision model reads the owner's own rows since the tool's last recorded
 * use and decides whether the owner explicitly agreed to this step after it was explained. Fail-closed — no consent,
 * no write; the tool answers NOT DONE and the agent asks the owner. A consent is used once (the use record in the
 * working state).
 *
 * The no-provenance paths are not gated: the HTTP-MCP server (the owner's own authenticated client — the owner is the
 * consent-giver) and the eval/admin executor-direct calls (fixture teardown).
 *
 * The step's target and consequence come from what the tool loads
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest}), the threshold is
 * `OwnerConsentRequest::MINIMUM_PROBABILITY`. A host test freezes the set of marked tools.
 *
 * `coveredByMandateActionClass`: a STANDING mandate (TIME_WINDOW or UNLIMITED, active and valid) of this action class
 * for the conversation's account and space covers the step — no consent question, the step runs, the turn event records
 * the mandate. The host decides per call whether the mandate applies
 * ({@see \DDD\Domain\AI\Services\Conversations\Agent\OwnerConsentsService::decideMandateCoverage()}): covered,
 * not applicable (the consent step as without a mandate), or refused with a reason (nothing runs). A ONE_INSTRUCTION
 * mandate never covers: it is granted from the same dialogue the consent step reads. Without a mandate, or after a
 * revoke, the step asks as before.
 *
 *   #[MCPTool, MCPToolRequiresOwnerConfirmation]
 *   public function deleteProject(..., OwnerConsentsService $ownerConsentsService, AgentToolCallProvenance $callProvenance): ... {}
 *
 *   #[MCPTool, MCPToolRequiresOwnerConfirmation(coveredByMandateActionClass: AgentMandate::ACTION_SUPPORT_REPLY)]
 *   public function replyToSupportTicket(...): ... {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolRequiresOwnerConfirmation
{
    /**
     * @param string|null $coveredByMandateActionClass the action class whose standing mandate covers the step; null = the
     *     step always asks the owner
     */
    public function __construct(
        public ?string $coveredByMandateActionClass = null
    ) {
    }
}
