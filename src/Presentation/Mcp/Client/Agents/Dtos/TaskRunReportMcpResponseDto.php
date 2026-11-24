<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\Agents\Dtos;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentClock;
use DDD\Domain\AI\Entities\Conversations\Reports\AIConversationOwnerReport;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Presentation\Base\Dtos\RestResponseDto;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;

/**
 * What one scheduled or background run did and reported: the run's facts (state, start, schedule, summary line) and
 * the owner report it delivered ({@see AIConversationOwnerReport}, the content the owner received).
 */
class TaskRunReportMcpResponseDto extends RestResponseDto implements LlmMarkdownRenderable
{
    public const string NOTE_RUN_IN_PROGRESS = 'This run is still in progress — no report has been written yet; the owner is notified when it finishes.';

    /** @var int The run conversation's id. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public int $conversationId = 0;

    /** @var int|null The schedule this run belongs to. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?int $scheduleId = null;

    /** @var string|null The schedule's title (or its task, shortened) — what the owner calls this task. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $scheduleLabel = null;

    /** @var string The run state (COMPLETED, FAILED, IDLE, RUNNING, …). */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $runState = '';

    /** @var DateTime|null When the run started. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?DateTime $startedAt = null;

    /** @var string|null The run's summary title. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $summaryTitle = null;

    /** @var string|null The run's summary outcome ("what came of it"). */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $summaryOutcome = null;

    /** @var string Where the report was delivered: EMAIL or CHAT. */
    #[Parameter(in: Parameter::RESPONSE, required: true)]
    public string $reportChannel = '';

    /** @var AIConversationOwnerReport|null The delivered report (subject and body); null while the run is in progress. */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?AIConversationOwnerReport $ownerReport = null;

    /** @var string|null Guidance for the agent (e.g. the run is still in progress). */
    #[Parameter(in: Parameter::RESPONSE, required: false)]
    public ?string $note = null;

    public function toLlmMarkdown(): string
    {
        $headline = "## Run #{$this->conversationId}"
            . ($this->scheduleId !== null ? " of schedule #{$this->scheduleId}" : '')
            . ($this->scheduleLabel !== null && trim($this->scheduleLabel) !== '' ? ' · ' . trim($this->scheduleLabel) : '');
        $facts = [$this->runState];
        if ($this->startedAt !== null) {
            $facts[] = 'started ' . AgentClock::formatMoment($this->startedAt);
        }
        $facts[] = 'report via ' . $this->reportChannel;
        $lines = [$headline, '- ' . implode(' · ', $facts)];
        if ($this->summaryTitle !== null && trim($this->summaryTitle) !== '') {
            $lines[] = '- summary: ' . trim($this->summaryTitle);
        }
        if ($this->summaryOutcome !== null && trim($this->summaryOutcome) !== '') {
            $lines[] = '- outcome: ' . trim($this->summaryOutcome);
        }
        if ($this->note !== null && trim($this->note) !== '') {
            $lines[] = '- ' . trim($this->note);
        }
        if ($this->ownerReport !== null) {
            $lines[] = '';
            $lines[] = match ($this->ownerReport->bodySource) {
                AIConversationOwnerReport::BODY_SOURCE_OWNER_REPORT_MESSAGE => '### Report the run wrote for the owner',
                AIConversationOwnerReport::BODY_SOURCE_FINAL_ASSISTANT_TEXT => '### Final answer of the run',
                default => '### Run-ended notice',
            };
            $lines[] = $this->ownerReport->toLlmMarkdown();
        }
        return implode("\n", $lines);
    }
}
