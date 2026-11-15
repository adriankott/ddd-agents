<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Prompts;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationRunEndNotification;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A sub-agent learns how much of its result the main conversation is handed at once (the run-end notification's cut,
 * {@see DelegationRunEndNotification::VERDICT_MAX_LENGTH}): its directive names that length, taken from the constant,
 * so the result fits and the parent needs no further read.
 */
class SubAgentDirectiveNamesTheLengthTheParentReadsAtOnceTest extends TestCase
{
    public function testTheDirectiveNamesTheNotificationsLengthFromItsConstant(): void
    {
        $subAgentDirectiveText = (new ReflectionMethod(AIConversation::class, 'findSubAgentDirectiveText'))->invoke(new AIConversation());

        self::assertStringEndsWith(
            'The main conversation is handed its first ' . number_format(DelegationRunEndNotification::VERDICT_MAX_LENGTH)
                . ' characters (about ' . number_format(intdiv(DelegationRunEndNotification::VERDICT_MAX_LENGTH, 8)) . ' words) at once and reads the rest only with a further call: keep the result within that length, the '
                . 'findings first.',
            $subAgentDirectiveText
        );
        self::assertStringContainsString('handed its first 8,000 characters (about 1,000 words) at once', $subAgentDirectiveText);
        self::assertStringNotContainsString('{%', $subAgentDirectiveText);
    }
}
