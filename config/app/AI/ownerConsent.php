<?php

declare(strict_types=1);

/**
 * The thresholds of the owner-consent check — read as `Config::get('AI.ownerConsent')`
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentSettings}). An application
 * replaces this file whole with its own `config/app/AI/ownerConsent.php`; a missing or invalid key keeps its default.
 *
 * `minimumProbability.stepExplained`: the decision model's probability that the agent explained the step (its target
 * and its consequence) must reach this. `minimumProbability.ownerAgreed`: its probability that the owner agreed to
 * that step must reach this. A step is consented only when both do. Default 0.90 each: above every trap and below
 * every genuine consent of the recorded dialogues it was set on. Raising a value refuses more genuine yeses; lowering
 * it lets more traps through — measure on the host's own dialogues before changing it.
 */
return [
    'minimumProbability' => [
        'stepExplained' => 0.90,
        'ownerAgreed' => 0.90,
    ],
];
