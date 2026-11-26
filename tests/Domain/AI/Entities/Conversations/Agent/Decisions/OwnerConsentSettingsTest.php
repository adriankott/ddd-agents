<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Decisions;

use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentRequest;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentSettings;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\OwnerConsent\OwnerConsentVerdict;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The owner-consent thresholds come from `AI.ownerConsent`, one per probability (default 0.90 each); a request takes
 * them, and the verdict compares each probability with its own threshold.
 */
class OwnerConsentSettingsTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        OwnerConsentSettings::useForCurrentProcess(null);
        parent::tearDown();
    }

    public function testTheConfigNodeSetsBothThresholdsAndAnInvalidValueKeepsTheDefault(): void
    {
        $defaultSettings = OwnerConsentSettings::fromConfigNode(null);
        self::assertSame(0.90, $defaultSettings->minimumStepExplainedProbability);
        self::assertSame(0.90, $defaultSettings->minimumOwnerAgreedProbability);

        $configuredSettings = OwnerConsentSettings::fromConfigNode(['minimumProbability' => ['stepExplained' => 0.8, 'ownerAgreed' => 1]]);
        self::assertSame(0.8, $configuredSettings->minimumStepExplainedProbability);
        self::assertSame(1.0, $configuredSettings->minimumOwnerAgreedProbability);

        $invalidSettings = OwnerConsentSettings::fromConfigNode(['minimumProbability' => ['stepExplained' => 1.5, 'ownerAgreed' => '0.5']]);
        self::assertSame(0.90, $invalidSettings->minimumStepExplainedProbability, 'above 1 keeps the default');
        self::assertSame(0.90, $invalidSettings->minimumOwnerAgreedProbability, 'a string keeps the default');
    }

    public function testThePackageConfigFileHoldsTheDefaults(): void
    {
        $packageSettings = OwnerConsentSettings::fromConfigNode(require dirname(__DIR__, 7) . '/config/app/AI/ownerConsent.php');
        self::assertSame(OwnerConsentRequest::MINIMUM_PROBABILITY, $packageSettings->minimumStepExplainedProbability);
        self::assertSame(OwnerConsentRequest::MINIMUM_PROBABILITY, $packageSettings->minimumOwnerAgreedProbability);
    }

    public function testARequestTakesTheThresholdsAndTheVerdictComparesEachProbabilityWithItsOwn(): void
    {
        OwnerConsentSettings::useForCurrentProcess(OwnerConsentSettings::fromConfigNode(['minimumProbability' => ['stepExplained' => 0.7, 'ownerAgreed' => 0.95]]));
        $request = OwnerConsentRequest::forAction('updateAddress', 'ADDRESS_CHANGE', 'change the address', 'the profile is verified again', 'address');
        self::assertSame(0.7, $request->minimumStepExplainedProbability);
        self::assertSame(0.95, $request->minimumOwnerAgreedProbability);
        self::assertSame(0.95, $request->minimumProbability, 'the deprecated single threshold shows the higher one');

        $consenting = OwnerConsentVerdict::decided($request, 0.75, 0.96, 3, OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL);
        self::assertTrue($consenting->consented, 'each probability reaches its own threshold');
        $ownerAgreedTooLow = OwnerConsentVerdict::decided($request, 0.99, 0.94, 3, OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL);
        self::assertFalse($ownerAgreedTooLow->consented);
        $stepExplainedTooLow = OwnerConsentVerdict::decided($request, 0.69, 0.99, 3, OwnerConsentVerdict::DECIDED_BY_DECISION_MODEL);
        self::assertFalse($stepExplainedTooLow->consented);
    }
}
