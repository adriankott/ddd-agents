<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

/**
 * Implemented by a tool's response DTO that decides per call whether this result holds only content the platform
 * vouches for. It overrides the tool's static rule ({@see MCPToolContentTrust}) for this one call, in both directions:
 *
 * - true: every text of this result is the platform's own (staff-only records, text the platform composed) — the call
 *   counts as a trusted read: no screening, no reading rule, no consent question for a later write of the turn.
 * - false: the result holds text a third party wrote (a partner's or customer's text) — an untrusted read, also for a
 *   tool declared `#[MCPToolReturnsTrustedContent]`.
 *
 * A throw from `isTrustedContent()` counts as false. Decide from the data the result actually carries, never from a
 * request argument the model set:
 *
 *   public function isTrustedContent(): bool
 *   {
 *       foreach ($this->entityTexts->getElements() as $entityText) {
 *           if ($entityText->authorKind !== EntityText::AUTHOR_STAFF) {
 *               return false;
 *           }
 *       }
 *       return true;
 *   }
 */
interface MCPToolResultDeclaresContentTrust
{
    /**
     * @return bool true when every text of this result is content the platform vouches for
     */
    public function isTrustedContent(): bool;
}
