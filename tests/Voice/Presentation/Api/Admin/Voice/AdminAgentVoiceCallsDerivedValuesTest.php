<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Presentation\Api\Admin\Voice;

use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCall;
use DDD\Domain\AI\Entities\Voice\Calls\AgentVoiceCalls;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallDerivedValues;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallsDerivedValues;
use DDD\Presentation\Api\Admin\Voice\Dtos\AdminAgentVoiceCallsGetResponseDto;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;

/**
 * The derived values of the admin list as a set ({@see AdminAgentVoiceCallsDerivedValues}): an entry is found by the
 * key of its call, a second entry for the same call is not added, an entry without a call id is keyed by its object,
 * the entries keep the order of the calls, and the answer carries the set as `{"elements":[…],"objectType":…}`,
 * filled and empty.
 */
class AdminAgentVoiceCallsDerivedValuesTest extends AgentVoiceUnitTestCase
{
    /** The answer for two calls, exactly as sent */
    public const string FILLED_ANSWER = <<<'JSON'
        {"agentVoiceCalls":{"elements":[],"objectType":"DDD\\Domain\\AI\\Entities\\Voice\\Calls\\AgentVoiceCalls"},"agentVoiceCallDerivedValues":{"elements":[{"callId":8,"callState":"RINGING","objectType":"DDD\\Presentation\\Api\\Admin\\Voice\\Dtos\\AdminAgentVoiceCallDerivedValues"},{"callId":7,"callState":"ENDED","objectType":"DDD\\Presentation\\Api\\Admin\\Voice\\Dtos\\AdminAgentVoiceCallDerivedValues"}],"objectType":"DDD\\Presentation\\Api\\Admin\\Voice\\Dtos\\AdminAgentVoiceCallsDerivedValues"}}
        JSON;

    /** The answer for no call, exactly as sent */
    public const string EMPTY_ANSWER = <<<'JSON'
        {"agentVoiceCalls":{"elements":[],"objectType":"DDD\\Domain\\AI\\Entities\\Voice\\Calls\\AgentVoiceCalls"},"agentVoiceCallDerivedValues":{"elements":[],"objectType":"DDD\\Presentation\\Api\\Admin\\Voice\\Dtos\\AdminAgentVoiceCallsDerivedValues"}}
        JSON;

    public function testAnEntryIsFoundByTheKeyOfItsCall(): void
    {
        $derivedValuesOfCall8 = static::buildDerivedValues(8, AgentVoiceCall::CALL_STATE_RINGING);
        $derivedValuesOfCall7 = static::buildDerivedValues(7, AgentVoiceCall::CALL_STATE_ENDED);
        $agentVoiceCallsDerivedValues = new AdminAgentVoiceCallsDerivedValues();
        $agentVoiceCallsDerivedValues->add($derivedValuesOfCall8, $derivedValuesOfCall7);

        static::assertSame(AdminAgentVoiceCallDerivedValues::uniqueKeyStatic(7), $derivedValuesOfCall7->uniqueKey());
        static::assertSame($derivedValuesOfCall7, $agentVoiceCallsDerivedValues->getByUniqueKey(AdminAgentVoiceCallDerivedValues::uniqueKeyStatic(7)));
        static::assertSame($derivedValuesOfCall8, $agentVoiceCallsDerivedValues->getByUniqueKey(AdminAgentVoiceCallDerivedValues::uniqueKeyStatic(8)));
        static::assertNull($agentVoiceCallsDerivedValues->getByUniqueKey(AdminAgentVoiceCallDerivedValues::uniqueKeyStatic(9)));
        static::assertSame([$derivedValuesOfCall8, $derivedValuesOfCall7], $agentVoiceCallsDerivedValues->getElements(), 'in the order of the calls');
    }

    public function testASecondEntryForTheSameCallIsNotAdded(): void
    {
        $firstEntry = static::buildDerivedValues(7, AgentVoiceCall::CALL_STATE_IN_PROGRESS);
        $secondEntry = static::buildDerivedValues(7, AgentVoiceCall::CALL_STATE_ENDED);
        $agentVoiceCallsDerivedValues = new AdminAgentVoiceCallsDerivedValues();

        $agentVoiceCallsDerivedValues->add($firstEntry, $secondEntry);

        static::assertSame([$firstEntry], $agentVoiceCallsDerivedValues->getElements());
        static::assertSame(AgentVoiceCall::CALL_STATE_IN_PROGRESS, $agentVoiceCallsDerivedValues->getByUniqueKey(AdminAgentVoiceCallDerivedValues::uniqueKeyStatic(7))?->callState);
    }

    public function testAnEntryWithoutACallIdIsKeyedByItsObject(): void
    {
        $firstEntryWithoutCall = new AdminAgentVoiceCallDerivedValues();
        $secondEntryWithoutCall = new AdminAgentVoiceCallDerivedValues();
        $agentVoiceCallsDerivedValues = new AdminAgentVoiceCallsDerivedValues();

        $agentVoiceCallsDerivedValues->add($firstEntryWithoutCall, $secondEntryWithoutCall, $firstEntryWithoutCall);

        static::assertSame(AdminAgentVoiceCallDerivedValues::uniqueKeyStatic(spl_object_id($firstEntryWithoutCall)), $firstEntryWithoutCall->uniqueKey());
        static::assertSame([$firstEntryWithoutCall, $secondEntryWithoutCall], $agentVoiceCallsDerivedValues->getElements());
    }

    public function testTheAnswerCarriesTheFilledSetAsElementsAndObjectType(): void
    {
        $agentVoiceCallsDerivedValues = new AdminAgentVoiceCallsDerivedValues();
        $derivedValuesOfCall8 = static::buildDerivedValues(8, AgentVoiceCall::CALL_STATE_RINGING);
        $derivedValuesOfCall7 = static::buildDerivedValues(7, AgentVoiceCall::CALL_STATE_ENDED);
        $agentVoiceCallsDerivedValues->add($derivedValuesOfCall8, $derivedValuesOfCall7);

        static::assertSame(static::FILLED_ANSWER, static::buildAnswer($agentVoiceCallsDerivedValues)->getContent());
    }

    public function testTheAnswerCarriesTheEmptySetAsElementsAndObjectType(): void
    {
        static::assertSame(static::EMPTY_ANSWER, static::buildAnswer(new AdminAgentVoiceCallsDerivedValues())->getContent());
    }

    protected static function buildAnswer(AdminAgentVoiceCallsDerivedValues $agentVoiceCallsDerivedValues): AdminAgentVoiceCallsGetResponseDto
    {
        $responseDto = new AdminAgentVoiceCallsGetResponseDto();
        $responseDto->agentVoiceCalls = new AgentVoiceCalls();
        $responseDto->agentVoiceCallDerivedValues = $agentVoiceCallsDerivedValues;

        return $responseDto;
    }

    protected static function buildDerivedValues(int $callId, string $callState): AdminAgentVoiceCallDerivedValues
    {
        $agentVoiceCallDerivedValues = new AdminAgentVoiceCallDerivedValues();
        $agentVoiceCallDerivedValues->callId = $callId;
        $agentVoiceCallDerivedValues->callState = $callState;

        return $agentVoiceCallDerivedValues;
    }
}
