<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettingsOverride;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The context-retention override carries the tool delivery and the cold-skill drop switch: parsed to their canonical
 * values, counted as passed by `isEmpty()`, written onto a copy of the settings by `applyTo()` only when passed.
 */
class ContextRetentionSettingsOverrideToolDeliveryTest extends UnitTestCase
{
    public function testBlankValuesGiveNoOverride(): void
    {
        self::assertNull(ContextRetentionSettingsOverride::fromRawValues(toolDelivery: ' ', dropColdSkillsAtCompactionRebase: ''));
    }

    public function testTheToolDeliveryAloneIsAnOverride(): void
    {
        $contextRetentionSettingsOverride = ContextRetentionSettingsOverride::fromRawValues(toolDelivery: ' calltooljsonschema ');

        self::assertNotNull($contextRetentionSettingsOverride);
        self::assertFalse($contextRetentionSettingsOverride->isEmpty());
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA, $contextRetentionSettingsOverride->toolDelivery);
        self::assertNull($contextRetentionSettingsOverride->dropColdSkillsAtCompactionRebase);
    }

    public function testTheDropSwitchAloneIsAnOverrideInBothDirections(): void
    {
        $switchedOn = ContextRetentionSettingsOverride::fromRawValues(dropColdSkillsAtCompactionRebase: 'ON');
        $switchedOff = ContextRetentionSettingsOverride::fromRawValues(dropColdSkillsAtCompactionRebase: ' off ');

        self::assertNotNull($switchedOn);
        self::assertNotNull($switchedOff);
        self::assertTrue($switchedOn->dropColdSkillsAtCompactionRebase);
        self::assertFalse($switchedOff->dropColdSkillsAtCompactionRebase);
        self::assertFalse($switchedOff->isEmpty(), 'off is a passed value, not an absent one');
        self::assertNull($switchedOff->toolDelivery);
    }

    public function testAnOverrideWithoutTheTwoFieldsIsEmpty(): void
    {
        self::assertTrue((new ContextRetentionSettingsOverride())->isEmpty());
    }

    public function testApplyToWritesThePassedFieldsOntoACopy(): void
    {
        $storedSettings = ContextRetentionSettings::newInstance();
        $storedSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL;
        $storedSettings->dropColdSkillsAtCompactionRebase = true;

        $appliedSettings = ContextRetentionSettingsOverride::fromRawValues(toolDelivery: 'native', dropColdSkillsAtCompactionRebase: 'off')
            ?->applyTo($storedSettings);

        self::assertNotNull($appliedSettings);
        self::assertNotSame($storedSettings, $appliedSettings);
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_NATIVE, $appliedSettings->toolDelivery);
        self::assertFalse($appliedSettings->dropColdSkillsAtCompactionRebase);
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, $storedSettings->toolDelivery, 'the settings passed in stay');
        self::assertTrue($storedSettings->dropColdSkillsAtCompactionRebase);
    }

    public function testApplyToKeepsTheStoredFieldsAnOverrideDidNotPass(): void
    {
        $storedSettings = ContextRetentionSettings::newInstance();
        $storedSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA;
        $storedSettings->dropColdSkillsAtCompactionRebase = false;

        $appliedSettings = ContextRetentionSettingsOverride::fromRawValues(true)?->applyTo($storedSettings);

        self::assertNotNull($appliedSettings);
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA, $appliedSettings->toolDelivery);
        self::assertFalse($appliedSettings->dropColdSkillsAtCompactionRebase);
    }

    public function testAnUnknownToolDeliveryIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("toolDelivery must be one of native, callTool, callToolJsonSchema, got 'call_tool'");
        ContextRetentionSettingsOverride::fromRawValues(toolDelivery: 'call_tool');
    }

    public function testADropSwitchOtherThanOnOrOffIsRefused(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage("dropColdSkillsAtCompactionRebase must be on or off, got 'yes'");
        ContextRetentionSettingsOverride::fromRawValues(dropColdSkillsAtCompactionRebase: 'yes');
    }
}
