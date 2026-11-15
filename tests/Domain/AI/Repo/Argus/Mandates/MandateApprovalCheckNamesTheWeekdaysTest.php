<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Mandates;

use DateTimeZone;
use DDD\Domain\AI\Repo\Argus\Mandates\ArgusAgentMandateApproval;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The approval check's payload names the weekday of now and of the requested window end on the dialogue's clock: a
 * period named by a weekday or a week ("until the end of next week") was worked out from the dates and both readings
 * were refused in turn. The weekday is the one of that clock, not of UTC.
 */
class MandateApprovalCheckNamesTheWeekdaysTest extends UnitTestCase
{
    public function testThePayloadNamesTheWeekdayOfNowAndOfTheWindowEndOnTheDialoguesClock(): void
    {
        $approval = static::instantiateWithoutConstructor(ArgusAgentMandateApproval::class);
        $approval->dialogueTimezone = new DateTimeZone('Pacific/Auckland');
        // Sunday 2026-11-22 20:00 UTC is Monday 09:00 in Auckland
        $approval->requestedValidUntil = DateTime::fromString('2026-11-22 20:00:00');
        $approval->requestedScopeType = 'TIME_WINDOW';

        $payload = json_decode((string)$approval->getUserContent(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('Monday', $payload['requestedValidUntilWeekday']);
        self::assertSame(
            (new \DateTime('now', new DateTimeZone('Pacific/Auckland')))->format('l'),
            $payload['currentWeekday']
        );
    }

    public function testWithoutAWindowEndItsWeekdayIsNull(): void
    {
        $approval = static::instantiateWithoutConstructor(ArgusAgentMandateApproval::class);
        $approval->requestedScopeType = 'UNLIMITED';

        $payload = json_decode((string)$approval->getUserContent(), true, 8, JSON_THROW_ON_ERROR);

        self::assertNull($payload['requestedValidUntilWeekday']);
    }
}
