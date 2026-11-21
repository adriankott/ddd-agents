<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Tests\Agents\Support\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The tool delivery and the cold-skill drop are stamped at a conversation's creation: a new conversation gets the
 * global default (built-in `callTool` / true), an explicitly requested value wins, an invalid value is ignored. The
 * getters read the stamp only, so a conversation without it stays on the native delivery and drops nothing; both act
 * only under the append-only skill loading.
 */
class ContextRetentionSettingsToolDeliveryStampTest extends UnitTestCase
{
    public function testTheBuiltInDefaultsAreCallToolAndTheDrop(): void
    {
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, ContextRetentionSettings::resolveDefaultToolDelivery());
        self::assertTrue(ContextRetentionSettings::resolveDefaultDropColdSkillsAtCompactionRebase());
    }

    public function testANewConversationIsStampedWithTheDefaults(): void
    {
        $settingsForNewConversation = ContextRetentionSettings::buildForNewConversation(null, ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND);

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, $settingsForNewConversation->toolDelivery);
        self::assertTrue($settingsForNewConversation->dropColdSkillsAtCompactionRebase);
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, $settingsForNewConversation->getToolDelivery());
        self::assertTrue($settingsForNewConversation->deliversToolsThroughCallTool());
        self::assertTrue($settingsForNewConversation->dropsColdSkillsAtCompactionRebase());
    }

    public function testExplicitlyRequestedValuesWinOverTheSettingsAndTheDefaults(): void
    {
        $requestedSettings = ContextRetentionSettings::newInstance();
        $requestedSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA;
        $requestedSettings->dropColdSkillsAtCompactionRebase = true;

        $settingsForNewConversation = ContextRetentionSettings::buildForNewConversation(
            $requestedSettings,
            ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND,
            requestedToolDelivery: ' NATIVE ',
            requestedDropColdSkillsAtCompactionRebase: false
        );

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_NATIVE, $settingsForNewConversation->toolDelivery, 'canonicalised');
        self::assertFalse($settingsForNewConversation->dropColdSkillsAtCompactionRebase);
        self::assertFalse($settingsForNewConversation->deliversToolsThroughCallTool());
        self::assertFalse($settingsForNewConversation->dropsColdSkillsAtCompactionRebase());
        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA, $requestedSettings->toolDelivery, 'the argument is not changed');
        self::assertTrue($requestedSettings->dropColdSkillsAtCompactionRebase);
    }

    public function testTheSettingsValuesWinOverTheDefaults(): void
    {
        $requestedSettings = ContextRetentionSettings::newInstance();
        $requestedSettings->toolDelivery = 'calltooljsonschema';
        $requestedSettings->dropColdSkillsAtCompactionRebase = false;

        $settingsForNewConversation = ContextRetentionSettings::buildForNewConversation($requestedSettings, null);

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA, $settingsForNewConversation->toolDelivery);
        self::assertFalse($settingsForNewConversation->dropColdSkillsAtCompactionRebase);
    }

    public function testAnInvalidRequestedValueFallsBackToTheSettingsAndThenToTheDefault(): void
    {
        $requestedSettings = ContextRetentionSettings::newInstance();
        $requestedSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_NATIVE;

        self::assertSame(
            ContextRetentionSettings::TOOL_DELIVERY_NATIVE,
            ContextRetentionSettings::buildForNewConversation($requestedSettings, null, requestedToolDelivery: 'viaText')->toolDelivery
        );

        $requestedSettings->toolDelivery = 'sometimes';
        self::assertSame(
            ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL,
            ContextRetentionSettings::buildForNewConversation($requestedSettings, null, requestedToolDelivery: '')->toolDelivery
        );
    }

    public function testAnUnstampedConversationStaysNativeAndDropsNothing(): void
    {
        $unstampedSettings = ContextRetentionSettings::newInstance();
        $unstampedSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_NATIVE, $unstampedSettings->getToolDelivery(), 'never the global default');
        self::assertFalse($unstampedSettings->deliversToolsThroughCallTool());
        self::assertFalse($unstampedSettings->dropsColdSkillsAtCompactionRebase());
    }

    public function testAnInvalidStoredDeliveryReadsAsNative(): void
    {
        $storedSettings = ContextRetentionSettings::newInstance();
        $storedSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $storedSettings->toolDelivery = 'viaText';

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_NATIVE, $storedSettings->getToolDelivery());
        self::assertFalse($storedSettings->deliversToolsThroughCallTool());
    }

    public function testBothActOnlyUnderTheAppendOnlySkillLoading(): void
    {
        $swapSettings = ContextRetentionSettings::newInstance();
        $swapSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_SYSTEM_PROMPT_SWAP;
        $swapSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL;
        $swapSettings->dropColdSkillsAtCompactionRebase = true;

        self::assertSame(ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL, $swapSettings->getToolDelivery(), 'the stamp is read as it is');
        self::assertFalse($swapSettings->deliversToolsThroughCallTool(), 'inert under the system-prompt swap');
        self::assertFalse($swapSettings->dropsColdSkillsAtCompactionRebase());

        $appendSettings = clone $swapSettings;
        $appendSettings->skillLoadingStrategy = ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND;
        $appendSettings->toolDelivery = ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA;
        self::assertTrue($appendSettings->deliversToolsThroughCallTool(), 'the JSON-schema form calls through call_tool too');
        self::assertTrue($appendSettings->dropsColdSkillsAtCompactionRebase());
    }

    /** @return array<string, array{0: string|null, 1: string|null}> */
    public static function rawToolDeliveries(): array
    {
        return [
            'native' => ['native', ContextRetentionSettings::TOOL_DELIVERY_NATIVE],
            'call tool, other case and blanks' => [' CALLTOOL ', ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL],
            'json schema' => ['callToolJsonSchema', ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA],
            'unknown' => ['call_tool', null],
            'blank' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('rawToolDeliveries')]
    public function testTheToolDeliveryIsCanonicalised(?string $rawToolDelivery, ?string $expectedToolDelivery): void
    {
        self::assertSame($expectedToolDelivery, ContextRetentionSettings::canonicalToolDelivery($rawToolDelivery));
    }

    public function testTheTwoStampsAloneAreOnlyTheCreationStamp(): void
    {
        $stampedSettings = ContextRetentionSettings::buildForNewConversation(null, ContextRetentionSettings::SKILL_LOADING_STRATEGY_CONVERSATION_APPEND);

        self::assertTrue($stampedSettings->isOnlyTheSkillLoadingStrategyStamp());
    }
}
