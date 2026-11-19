<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Services\Conversations\AgentOwnerFacingTextTranslationService;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Infrastructure\Services\DDDService;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The owner-facing texts of the module in a process without the application's translation function `___()` (the
 * package's own run): a key without a translation answers its English text with the placeholders filled, a plural line
 * its plural English wording.
 */
class AgentOwnerFacingTextTranslationServiceTest extends UnitTestCase
{
    public function testTheApplicationTranslationFunctionIsNotDefinedInThePackageRun(): void
    {
        self::assertFalse(function_exists(AgentOwnerFacingTextTranslationService::HOST_TRANSLATION_FUNCTION_NAME));
    }

    public function testAKeyWithoutTranslationAnswersTheEnglishTextWithItsPlaceholders(): void
    {
        self::assertSame(
            'Schedule 42 cannot be resumed in status CLOSED.',
            static::getService()->translateOwnerFacingText(
                'Schedule %scheduleId% cannot be resumed in status %status%.',
                'de',
                ['scheduleId' => '42', 'status' => 'CLOSED']
            )
        );
    }

    public function testAPluralLineWithoutTranslationAnswersThePluralEnglishWording(): void
    {
        self::assertSame(
            '3 items are open',
            static::getService()->translateOwnerFacingText('%count% item is open', 'de', ['count' => 3], '%count% items are open', Translatable::CONTEXT_MANY)
        );
    }

    protected static function getService(): AgentOwnerFacingTextTranslationService
    {
        /** @var AgentOwnerFacingTextTranslationService $agentOwnerFacingTextTranslationService */
        $agentOwnerFacingTextTranslationService = DDDService::instance()->getService(AgentOwnerFacingTextTranslationService::class);
        return $agentOwnerFacingTextTranslationService;
    }
}
