<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Declares that an #[MCPTool] method — or every tool of an MCPPackage — returns only content the platform vouches for:
 * the platform's own records, text the platform composed, the owner's own data written by the owner. Without it a READ
 * or WORKSPACE tool counts as returning untrusted content ({@see MCPToolContentTrust}): its result is screened when the
 * tool-result screen runs, it carries the reading rule, and a write later in the same turn needs the owner's consent
 * ({@see \DDD\Presentation\Mcp\Services\MCPToolExecutorService}).
 *
 * Never declare it for text a third party wrote (web pages, mail, a customer's message, a file of a drive or a
 * workspace, the output of a shell) — not even when the platform stored it. An application that cannot edit a vendor
 * package lists it in `config/app/AI/untrustedContent.php` (`trustedPackages`, `trustedTools`).
 *
 *   #[MCPTool, MCPToolReturnsTrustedContent('the account\'s own invoices as stored by the platform')]
 *   public function listInvoices(...): ... {}
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class MCPToolReturnsTrustedContent
{
    public function __construct(public string $reason = '')
    {
    }
}
