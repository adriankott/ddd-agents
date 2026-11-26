<?php

declare(strict_types=1);

/**
 * How much of the conversation the decision models read — read as `Config::get('AI.decisionContext')`
 * ({@see \DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionContextSettings}). An application replaces
 * this file whole with its own `config/app/AI/decisionContext.php`; a missing or invalid key keeps its default.
 *
 * Every cut is marked in the text the model reads (`[… 804 characters cut at the start]`). An assistant text keeps its
 * END (its question and offer), an owner message its START (its answer). The normal path stays short; raise a limit
 * only for a host whose texts are long (support replies shown in full) and measure first.
 *
 * `ownerConsent`: an assistant explanation keeps `lineCharacters` plus the length of the step and its consequence, at
 * most `assistantLineMaxCharacters`; owner and widget lines keep `lineCharacters`. When a line was cut and the first
 * pass leaves the yes short of consent for want of context (the model says the excerpt is not enough, or the owner's
 * yes is clear and the step-explained probability lies between `nearMissFloor` and its threshold), ONE second pass
 * reads every line up to `expandedLineCharacters`. The thresholds never change; the second pass only adds context.
 * `secondPass` false switches the second pass off.
 *
 * `turnStart` and `stallRecovery`: the owner message and the assistant text before it.
 */
return [
    'ownerConsent' => [
        'lineCharacters' => 700,
        'assistantLineMaxCharacters' => 4000,
        'expandedLineCharacters' => 12000,
        'secondPass' => true,
        'contextSufficientMinimum' => 0.5,
        'nearMissFloor' => 0.5,
    ],
    'turnStart' => [
        'ownerMessageCharacters' => 600,
        'assistantTextCharacters' => 300,
    ],
    'stallRecovery' => [
        'ownerMessageCharacters' => 600,
        'assistantTextCharacters' => 300,
    ],
];
