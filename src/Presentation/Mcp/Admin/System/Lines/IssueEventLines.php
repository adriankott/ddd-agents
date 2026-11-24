<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Admin\System\Lines;

use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmSecretRedaction;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Domain\Common\Entities\Issues\Overview\IssueEventDetail;
use DDD\Domain\Common\Entities\Issues\Overview\IssueEventFrame;

/**
 * One event of an error group as lines: when, the level, where it happened (route, command or worker message), the
 * status, the release, the message (first line, masked, cut), and the first frames of the application's own code.
 * Never the values of route parameters, the client's identity, breadcrumbs or the whole stack trace: they can carry
 * personal data and fill the result. The text is what the system logged: the tool marks these lines untrusted.
 * Example:
 *   2026-11-10 10:57:03 UTC · ERROR · GET orders_list · 500 · release 4f2a91c · "An exception occurred while executing a query"
 *     at src/Domain/Orders/Services/OrdersService.php:212 findOrders
 */
class IssueEventLines
{
    /** @var int The characters of the message an event shows */
    public const int MESSAGE_LENGTH = 240;

    /** @var int The frames an event shows */
    public const int MAX_FRAMES = 4;

    /** @var int The characters of a release (git sha) an event shows */
    public const int RELEASE_LENGTH = 10;

    /** @var string[] Where the application lives in a container; cut from the file of a frame */
    public const array APPLICATION_ROOTS = ['/var/www/app/', '/var/www/html/', '/app/'];

    /**
     * @param IssueEventDetail $issueEventDetail
     */
    public function __construct(protected IssueEventDetail $issueEventDetail)
    {
    }

    /**
     * @return string[]
     */
    public function toLlmLines(): array
    {
        $issueEventDetail = $this->issueEventDetail;
        $headParts = [SystemLineFormats::formatTime($issueEventDetail->timestamp, true)];
        if (($issueEventDetail->level ?? '') !== '') {
            $headParts[] = mb_strtoupper((string)$issueEventDetail->level);
        }
        $where = trim(($issueEventDetail->httpMethod ?? '') . ' ' . LlmTextRendering::shorten(LlmSecretRedaction::redact(LlmTextRendering::toSingleLine((string)($issueEventDetail->route ?? ''))), 120));
        if ($where !== '') {
            $headParts[] = $where;
        }
        if (($issueEventDetail->command ?? '') !== '') {
            $headParts[] = 'command ' . SystemLineFormats::commandNameOfCommandLine($issueEventDetail->command);
        }
        if ($issueEventDetail->worker !== null && ($issueEventDetail->worker->messageClass ?? '') !== '') {
            $headParts[] = 'worker message ' . SystemLineFormats::shortClassName($issueEventDetail->worker->messageClass)
                . ($issueEventDetail->worker->attempt !== null ? ' (attempt ' . $issueEventDetail->worker->attempt . ($issueEventDetail->worker->final ? ', the last one' : '') . ')' : '');
        }
        if ($issueEventDetail->httpStatus !== null) {
            $headParts[] = (string)$issueEventDetail->httpStatus;
        }
        if (($issueEventDetail->release ?? '') !== '') {
            $headParts[] = 'release ' . substr((string)$issueEventDetail->release, 0, static::RELEASE_LENGTH);
        }
        $message = SystemLineFormats::quoteLoggedText($issueEventDetail->message, static::MESSAGE_LENGTH);
        if ($message !== '') {
            $headParts[] = $message;
        }
        $lines = [implode(' · ', $headParts)];
        foreach ($this->selectFrames() as $issueEventFrame) {
            $lines[] = '  at ' . static::describeFrame($issueEventFrame);
        }
        foreach ($issueEventDetail->causes->getElements() as $issueEventCause) {
            $causeMessage = SystemLineFormats::quoteLoggedText($issueEventCause->message, static::MESSAGE_LENGTH);
            $lines[] = '  caused by ' . SystemLineFormats::shortClassName($issueEventCause->type) . ($causeMessage !== '' ? ': ' . $causeMessage : '');
        }
        return $lines;
    }

    /**
     * @return IssueEventFrame[] The first frames of the application's own code; without any, the first frames
     */
    protected function selectFrames(): array
    {
        $issueEventFrames = $this->issueEventDetail->backendFrames->getElements();
        if ($issueEventFrames === []) {
            $issueEventFrames = $this->issueEventDetail->frames->getElements();
        }
        $applicationFrames = [];
        foreach ($issueEventFrames as $issueEventFrame) {
            if ($issueEventFrame->inApp) {
                $applicationFrames[] = $issueEventFrame;
            }
        }
        return array_slice($applicationFrames !== [] ? $applicationFrames : $issueEventFrames, 0, static::MAX_FRAMES);
    }

    /**
     * @param IssueEventFrame $issueEventFrame
     * @return string `src/Domain/…/OrdersService.php:212 findOrders`
     */
    public static function describeFrame(IssueEventFrame $issueEventFrame): string
    {
        $file = (string)($issueEventFrame->resolvedFile ?? $issueEventFrame->file ?? '');
        foreach (static::APPLICATION_ROOTS as $applicationRoot) {
            if (str_starts_with($file, $applicationRoot)) {
                $file = substr($file, strlen($applicationRoot));
                break;
            }
        }
        $line = $issueEventFrame->resolvedLine ?? $issueEventFrame->line;
        $function = LlmTextRendering::toSingleLine((string)($issueEventFrame->resolvedFunction ?? $issueEventFrame->function ?? ''));
        return LlmTextRendering::shorten($file !== '' ? $file : 'unknown file', 140) . ($line !== null ? ':' . $line : '')
            . ($function !== '' ? ' ' . LlmTextRendering::shorten(SystemLineFormats::shortClassName($function), 80) : '');
    }
}
