<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

/** The expected close rule and refusal stamp per site of {@see DecisionSitesAlreadyDecidedThisTurnTest}. */
class DecisionSitesAlreadyDecidedThisTurnCells
{
    public const array CLOSE_RULES_AND_REFUSAL_STAMPS = [
        'replyLanguage' => 'closes on a parsable answer only | refused: card replyLanguage anchor applied; event request turnStart decidedBy="fallback:refused"',
        'skillPreload roots' => 'closes on a parsable answer only | refused: event decision skillPreload decidedBy="fallback:refused"; card skillPreload fallback:refused not applied; event request turnStart decidedBy="fallback:refused"',
        'skillPreload follow-up pass' => 'closes on a parsable answer only | refused: event decision skillPreload pass2:Fixture.Profile decidedBy="fallback:refused"; card skillPreload fallback:refused not applied; event request turnStart decidedBy="fallback:refused"; result none (void)',
        'skillPreload loaded skill child' => 'closes on a parsable answer only | refused: event decision skillPreload loadedSkill:Fixture.Profile decidedBy="fallback:refused"; card skillPreload fallback:refused not applied; event request turnStart decidedBy="fallback:refused"',
        'skillPreload after the model load' => 'closes on a parsable answer only | refused: event decision skillPreload modelLoad:Fixture.Profile decidedBy="fallback:refused"; card skillPreload fallback:refused not applied; event request turnStart decidedBy="fallback:refused"; result sentence ""',
        'reasoningEffort' => 'closes on a parsable answer only | refused: card reasoningEffort fallback:refused not applied; event decision reasoningEffort decidedBy="fallback:refused"; event request turnStart decidedBy="fallback:refused"',
        'planDynamics' => 'closes on a parsable answer only | refused: card planDynamics fallback:refused not applied; event decision planDynamics decidedBy="fallback:refused"; event request turnStart decidedBy="fallback:refused"',
        'ownerMessageInjection' => 'never closes | refused: card ownerMessageInjection fallback:refused not applied',
        'stallRecovery' => 'closes on a parsable answer only | refused: card stallRecovery fallback:refused not applied; event decision stallRecovery decidedBy="fallback:refused"; result move null',
        'turnClosing' => 'closes on a parsable answer only | refused: card turnClosing fallback:refused not applied; event decision turnClosing decidedBy="fallback:refused"; result outcome {"kind":"unavailable","wouldBounce":false,"reason":"refused"}',
        'ownerConsent' => 'closes on a parsable answer only | refused: result verdict {"decidedBy":null,"consented":false,"failure":"refused","consentingOwnerMessageIndex":6,"callOutcome":"refused"}',
    ];
}
