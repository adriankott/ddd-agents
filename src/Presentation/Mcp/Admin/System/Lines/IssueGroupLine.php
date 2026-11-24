<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmLineRenderable;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmSecretRedaction;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Domain\Common\Entities\Issues\IssueGroup;

/**
 * One error group of the issues registry as a line: environment, status, where it happens, the exception and the
 * sample message (first line, masked, cut), how often, when last and first, its entry point as people read it, and the
 * start of its hash. The message can quote what a client sent: the tool marks these lines untrusted.
 * Example: `#10021 · prod · OPEN · HTTP · DriverException: "An exception occurred while executing a query" · 4 events · last seen 2026-11-10 10:57 UTC · first seen 2026-11-02 · GET /api/admin/partners/worlds/list · hash 4bf1c0d0…`
 */
class IssueGroupLine implements LlmLineRenderable
{
    /** @var int The characters of the sample message a list line shows */
    public const int MESSAGE_LENGTH = 110;

    /** @var int The characters of the hash a list line shows (the card shows it whole) */
    public const int HASH_PREFIX_LENGTH = 8;

    /**
     * @param IssueGroup $issueGroup
     * @param string|null $entryPoint The entry point as IssuesOverviewService::describeEntryPoint() words it
     * @param int $messageLength The characters of the sample message
     */
    public function __construct(protected IssueGroup $issueGroup, protected ?string $entryPoint = null, protected int $messageLength = self::MESSAGE_LENGTH)
    {
    }

    /**
     * @param IssueGroup $issueGroup
     * @param int $messageLength
     * @return string The exception's short name and the sample message: `DriverException: "…"`
     */
    public static function describeError(IssueGroup $issueGroup, int $messageLength): string
    {
        $exceptionName = SystemLineFormats::shortClassName($issueGroup->exceptionType);
        $sampleMessage = trim((string)($issueGroup->sampleMessage ?? ''));
        // the registry stores most messages as "<ExceptionName>: <message>"
        if ($exceptionName !== '' && str_starts_with($sampleMessage, $exceptionName . ':')) {
            $sampleMessage = ltrim(substr($sampleMessage, strlen($exceptionName) + 1));
        }
        $quotedMessage = SystemLineFormats::quoteLoggedText($sampleMessage, $messageLength);
        if ($exceptionName === '') {
            return $quotedMessage !== '' ? $quotedMessage : 'no message';
        }
        return $exceptionName . ($quotedMessage !== '' ? ': ' . $quotedMessage : '');
    }

    /**
     * @param IssueGroup $issueGroup
     * @return string What a result calls the group: `DriverException in prod`
     */
    public static function describeName(IssueGroup $issueGroup): string
    {
        $exceptionName = SystemLineFormats::shortClassName($issueGroup->exceptionType);
        return ($exceptionName !== '' ? $exceptionName : 'error') . ' in ' . ($issueGroup->environment ?? 'no environment');
    }

    /**
     * @return string
     */
    public function toLlmLine(): string
    {
        $issueGroup = $this->issueGroup;
        $lineParts = [
            '#' . $issueGroup->id,
            (string)($issueGroup->environment ?? 'no environment'),
            $issueGroup->status,
        ];
        if (($issueGroup->section ?? '') !== '') {
            $lineParts[] = (string)$issueGroup->section;
        }
        $lineParts[] = static::describeError($issueGroup, $this->messageLength);
        $lineParts[] = SystemLineFormats::countOf($issueGroup->eventCount, 'event');
        $lineParts[] = 'last seen ' . SystemLineFormats::formatTime($issueGroup->lastSeenAt);
        if ($issueGroup->firstSeenAt !== null) {
            $lineParts[] = 'first seen ' . gmdate('Y-m-d', $issueGroup->firstSeenAt->getTimestamp());
        }
        if ($this->entryPoint !== null && $this->entryPoint !== '') {
            $lineParts[] = LlmSecretRedaction::redact(LlmTextRendering::toSingleLine($this->entryPoint));
        }
        if ($issueGroup->isRegression) {
            $lineParts[] = 'regression (came back after it was resolved)';
        }
        $lineParts[] = 'hash ' . substr($issueGroup->groupHash, 0, static::HASH_PREFIX_LENGTH) . '…';
        return implode(' · ', $lineParts);
    }
}
