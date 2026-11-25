<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\SessionWorker;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCallRefusal;
use DDD\Domain\AI\Services\Voice\AgentVoiceSessionContextsService;

/**
 * The texts the voice model is told mid-call, as marker sentences: a test sees WHICH text the worker asked for and
 * with what. The wording itself is the contexts service's own (tested with that service).
 */
class RecordingAgentVoiceSessionContextsService extends AgentVoiceSessionContextsService
{
    public const string CALL_LANGUAGE_CLAUSE = ' [keep the call language]';

    public const string SILENCE_CHECK = 'SILENCE CHECK';

    public const string QUESTION_REPEAT = 'QUESTION REPEAT';

    public const string GOODBYE_AFTER_SILENCE_CHECKS = 'GOODBYE AFTER THE SILENCE CHECKS';

    public const string GOODBYE_AFTER_THE_HANG_UP_TIME = 'GOODBYE AFTER THE HANG-UP TIME';

    public const string REFUSED_CALL_GOODBYE_PREFIX = 'REFUSED CALL GOODBYE: ';

    public const string WORKER_STOPPING_GOODBYE = 'WORKER STOPPING GOODBYE';

    /** @var AgentVoiceCallRefusal[] Every refusal a goodbye was asked for */
    public array $refusalsAskedFor = [];

    public function buildCallLanguageClause(AgentVoiceCall $agentVoiceCall): string
    {
        return static::CALL_LANGUAGE_CLAUSE;
    }

    public function buildSilenceCheckInstruction(AgentVoiceCall $agentVoiceCall): string
    {
        return static::SILENCE_CHECK;
    }

    public function buildQuestionRepeatInstruction(AgentVoiceCall $agentVoiceCall): string
    {
        return static::QUESTION_REPEAT;
    }

    public function buildGoodbyeInstruction(AgentVoiceCall $agentVoiceCall, bool $afterSilenceChecks = false): string
    {
        return $afterSilenceChecks ? static::GOODBYE_AFTER_SILENCE_CHECKS : static::GOODBYE_AFTER_THE_HANG_UP_TIME;
    }

    /** @var bool When true, a refusal that is no limit refusal gets the package's own neutral goodbye */
    public bool $answersNonLimitRefusalsWithTheNeutralGoodbye = false;

    public function buildRefusedCallGoodbyeInstruction(AgentVoiceCall $agentVoiceCall, AgentVoiceCallRefusal $agentVoiceCallRefusal): string
    {
        $this->refusalsAskedFor[] = $agentVoiceCallRefusal;
        if ($this->answersNonLimitRefusalsWithTheNeutralGoodbye && !$agentVoiceCallRefusal->isLimitRefusal()) {
            return parent::buildRefusedCallGoodbyeInstruction($agentVoiceCall, $agentVoiceCallRefusal);
        }

        return static::REFUSED_CALL_GOODBYE_PREFIX . $agentVoiceCallRefusal->code;
    }

    public function buildWorkerStoppingGoodbyeInstruction(AgentVoiceCall $agentVoiceCall): string
    {
        return static::WORKER_STOPPING_GOODBYE;
    }
}
