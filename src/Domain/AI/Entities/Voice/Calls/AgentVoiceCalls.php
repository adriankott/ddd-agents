<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Voice\Calls;

use DDD\Domain\AI\Repo\DB\Voice\Calls\DBAgentVoiceCalls;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallsService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\OrderByOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Presentation\Base\Dtos\RequestDto;

/**
 * A list of voice calls: the call log of an account, the calls of a conversation, the calls open now.
 *
 * @property AgentVoiceCall[] $elements
 * @method AgentVoiceCall getByUniqueKey(string $uniqueKey)
 * @method AgentVoiceCall[] getElements()
 * @method AgentVoiceCall first()
 * @method static AgentVoiceCallsService getService()
 * @phpstan-consistent-constructor
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentVoiceCalls::class, forceDBEntityModelCreation: true)]
class AgentVoiceCalls extends EntitySet implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait;
    use QueryOptionsTrait;

    /** Pins the service to its folder (the flat convention of getService() would miss `Services\Voice\…`). */
    public const string SERVICE_NAME = AgentVoiceCallsService::class;

    /**
     * The order a call log is read in when the caller asked for none: newest call first. `id desc` and not
     * `startedAt desc`: a call that was never answered has no `startedAt` and would sort to one end of the list; the
     * id grows with each creation and is never null.
     */
    public const string DEFAULT_ORDER_BY = 'id desc';

    /**
     * Takes the caller's query options off the request (`filters`, `orderBy`, `top`, `skip`, `expand`, `select`) and
     * falls back to {@see self::DEFAULT_ORDER_BY} when no order was asked for. The repository pins no order of its own,
     * because a fixed one is applied first and would win over the requested one. Shared by the list route and the
     * agent's `getVoiceCalls` tool, so both read the log the same way.
     *
     * The request is taken by value: it is read and never reassigned, and a by-reference parameter typed to the base
     * `RequestDto` would widen every caller's type.
     */
    public static function applyQueryOptionsFromRequestDto(RequestDto $requestDto): void
    {
        $queryOptions = static::getDefaultQueryOptions();
        $queryOptions->setQueryOptionsFromRequestDto($requestDto);
        if ($queryOptions->getOrderBy()) {
            return;
        }
        // a variable first: setOrderBy() takes its argument by reference
        $defaultOrderBy = OrderByOptions::fromString(static::DEFAULT_ORDER_BY);
        $queryOptions->setOrderBy($defaultOrderBy, validateAgainstDefinitions: false);
    }

    /** The call log as the model reads it, now, without the marker of the current conversation ({@see buildLlmMarkdown()}). */
    public function toLlmMarkdown(): string
    {
        return $this->buildLlmMarkdown(time(), null, false);
    }

    /**
     * The call log the model reads (what `getVoiceCalls` answers): one summary line with the counts, then one line per
     * call ({@see AgentVoiceCall::buildLlmMarkdownLine()}). The call whose conversation is `$currentConversationId`
     * carries the marker of the conversation the agent is answering in. `$onlyOpenCalls` words the summary as the list
     * of the calls ringing or running now. Pure fold over the loaded set.
     */
    public function buildLlmMarkdown(int $nowUnixTime, ?int $currentConversationId, bool $onlyOpenCalls): string
    {
        $markdownBlocks = [$this->buildLlmMarkdownSummaryLine($onlyOpenCalls)];
        foreach ($this->getElements() as $agentVoiceCall) {
            $markdownBlocks[] = $agentVoiceCall->buildLlmMarkdownLine(
                $nowUnixTime,
                $currentConversationId !== null && $agentVoiceCall->aiConversationId === $currentConversationId
            );
        }

        return implode("\n", $markdownBlocks);
    }

    /**
     * The calls among this set's elements that ring or run at `$nowUnixTime` ({@see AgentVoiceCall::isOpenAt()}), as
     * a set of their own in the order of this one.
     */
    public function getOpenCallsAt(int $nowUnixTime): static
    {
        $openCalls = new static();
        foreach ($this->getElements() as $agentVoiceCall) {
            if ($agentVoiceCall->isOpenAt($nowUnixTime)) {
                $openCalls->add($agentVoiceCall);
            }
        }

        return $openCalls;
    }

    /** The calls among this set's elements that are still running, as a set of their own in the order of this one. */
    public function getRunningCalls(): static
    {
        $runningCalls = new static();
        foreach ($this->getElements() as $agentVoiceCall) {
            if ($agentVoiceCall->isRunning()) {
                $runningCalls->add($agentVoiceCall);
            }
        }

        return $runningCalls;
    }

    /**
     * The summary line of the call log of this set: the total and the count per direction, newest first. A subclass
     * with further media adds its count per medium.
     */
    protected function buildLlmMarkdownSummaryLine(bool $onlyOpenCalls): string
    {
        $inboundCallCount = 0;
        foreach ($this->getElements() as $agentVoiceCall) {
            if ($agentVoiceCall->direction === AgentVoiceCall::DIRECTION_INBOUND) {
                $inboundCallCount++;
            }
        }

        return sprintf(
            '%s — %d total (%d inbound, %d outbound), newest first:',
            $onlyOpenCalls ? 'Calls ringing or running right now' : 'Calls',
            $this->count(),
            $inboundCallCount,
            $this->count() - $inboundCallCount
        );
    }
}
