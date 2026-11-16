<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonIdPlaceholders;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The one normalisation of the tool-call comparison: only the harness's own ids and the ids a case minted are replaced,
 * in id-named fields and as whole numbers in text; everything else stays raw.
 */
class ToolCallComparisonIdPlaceholdersTest extends UnitTestCase
{
    public function testHarnessIdsReadTheirDeclaredPlaceholderInFieldsAndText(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareHarnessId(7001234, '<conversation:setup>');

        self::assertSame(
            ['aiConversationId' => '<conversation:setup>', 'message' => 'conversation <conversation:setup> wrote'],
            $idPlaceholders->normalizeStructure(['aiConversationId' => 7001234, 'message' => 'conversation 7001234 wrote'])
        );
    }

    public function testMintedIdsAreNumberedByFirstAppearanceAcrossTheRun(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        // a foreign row minted at the same time (900001) never appears and takes no number
        $idPlaceholders->declareMintedIds(['EntityTempAIConversationMessages' => [900001, 900002, 900003]]);

        self::assertSame('<EntityTempAIConversationMessages#1>', $idPlaceholders->normalizeId(900003));
        self::assertSame('<EntityTempAIConversationMessages#2>', $idPlaceholders->normalizeId('900002'));
        self::assertSame('<EntityTempAIConversationMessages#1>', $idPlaceholders->normalizeId(900003), 'a later reference reads the same placeholder');
    }

    public function testPreExistingIdsShortNumbersAndNonIdKeysStayRawWhileAMintedIdInTextIsReplaced(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareMintedIds(['records' => [123456, 42]]);

        self::assertSame(
            ['agentSpaceId' => 21436118, 'count' => 123456, 'id' => '<records#1>', 'note' => 'phone 0221 5551234, row 42 and <records#1>'],
            $idPlaceholders->normalizeStructure(['agentSpaceId' => 21436118, 'count' => 123456, 'id' => 123456, 'note' => 'phone 0221 5551234, row 42 and 123456'])
        );
    }

    public function testAStatementConditionPairIsReadUnderItsColumn(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareMintedIds(['record_settings' => [555001]]);

        self::assertSame(
            ['whereValues' => [['column' => 'id', 'value' => '<record_settings#1>'], ['column' => 'type', 'value' => 555001]]],
            $idPlaceholders->normalizeStructure(['whereValues' => [['column' => 'id', 'value' => 555001], ['column' => 'type', 'value' => 555001]]])
        );
    }

    public function testAnIdTwoTablesMintedReadsTheSharedFamily(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareMintedIds(['tableA' => [777777], 'tableB' => [777777]]);

        self::assertSame('<id#1>', $idPlaceholders->normalizeId(777777));
    }

    public function testIdKeyNames(): void
    {
        foreach (['id', 'aiConversationId', 'record_id', 'externalID'] as $idKey) {
            self::assertTrue(ToolCallComparisonIdPlaceholders::isIdKey($idKey), $idKey);
        }
        foreach (['identity', 'valid', 'Id', 'paid', null] as $otherKey) {
            self::assertFalse(ToolCallComparisonIdPlaceholders::isIdKey($otherKey), (string)$otherKey);
        }
    }

    public function testAShortMintedIdIsReplacedWhereTheTextSaysItIsAnIdAndAFreeShortNumberStaysRaw(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareMintedIds(['EntityBuildRuns' => [1926], 'EntityBuildRunEvents' => [1306]]);

        // a tool result: JSON in the text of JSON (escaped quotes), a reference after `#`, a client key
        $resultText = '{"text":"{\"runId\":1926,\"admissionId\":\"1306\",\"definitionRevision\":1926}",'
            . '"nextStep":"build run #1926 is EMPTY","clientKey":"run1926_key21546638","note":"1926 pages, run 1926"}';
        self::assertSame(
            '{"text":"{\"runId\":<EntityBuildRuns#1>,\"admissionId\":\"<EntityBuildRunEvents#1>\",\"definitionRevision\":1926}",'
                . '"nextStep":"build run #<EntityBuildRuns#1> is EMPTY","clientKey":"run<EntityBuildRuns#1>_key21546638","note":"1926 pages, run 1926"}',
            $idPlaceholders->normalizeText($resultText),
            'id-named keys, `#` and a word-glued `…_` read the placeholder; a non-id key and free text keep the number'
        );
    }

    public function testAFullStopAfterALongIdNoLongerHidesItWhileADecimalFractionStillDoes(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareHarnessId(711166, '<conversation:build>');

        self::assertSame(
            'started in build conversation #<conversation:build>. Price 711166.5',
            $idPlaceholders->normalizeText('started in build conversation #711166. Price 711166.5')
        );
    }

    public function testAnUndeclaredShortIdStaysRawSoARealDifferenceIsStillVisible(): void
    {
        $recordedPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $recordedPlaceholders->declareMintedIds(['EntityBuildRuns' => [1926]]);
        $replayedPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $replayedPlaceholders->declareMintedIds(['EntityBuildRuns' => [1930]]);

        self::assertSame('{"runId":<EntityBuildRuns#1>}', $recordedPlaceholders->normalizeText('{"runId":1926}'));
        self::assertSame('{"runId":<EntityBuildRuns#1>}', $replayedPlaceholders->normalizeText('{"runId":1930}'));
        self::assertNotSame(
            $recordedPlaceholders->normalizeText('{"runId":1926,"templateRunId":777}'),
            $replayedPlaceholders->normalizeText('{"runId":1930,"templateRunId":778}'),
            'an id no run minted (a pre-existing row) stays raw: a code change that names another row is still reported'
        );
        $twoRunsPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $twoRunsPlaceholders->declareMintedIds(['EntityBuildRuns' => [1926, 1927]]);
        self::assertSame(
            '{"runId":<EntityBuildRuns#1>,"otherRunId":<EntityBuildRuns#2>}',
            $twoRunsPlaceholders->normalizeText('{"runId":1926,"otherRunId":1927}'),
            'two different minted rows keep two placeholders: a call naming the wrong run differs'
        );
    }

    public function testAnOpaqueTokenComparesByWhichValuesAreEqual(): void
    {
        $recordedPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $recordedPlaceholders->declareOpaqueTokenKeys(['idempotencyKey']);
        $replayedPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $replayedPlaceholders->declareOpaqueTokenKeys(['idempotencyKey']);

        // JSON column text (as a statement binds it) and a structured value
        self::assertSame(
            '{\"idempotencyKey\":\"<idempotencyKey#1>\",\"brief\":\"f16531e0\"}',
            $recordedPlaceholders->normalizeText('{\"idempotencyKey\":\"f16531e01538788b\",\"brief\":\"f16531e0\"}')
        );
        self::assertSame(['idempotencyKey' => '<idempotencyKey#1>'], $replayedPlaceholders->normalizeStructure(['idempotencyKey' => '8497aaeb0694']));
        // the record reuses its key (a retry); the replay mints a new one (a fresh run): still a difference
        self::assertSame('<idempotencyKey#1>', $recordedPlaceholders->normalizeOpaqueToken('idempotencyKey', 'f16531e01538788b'));
        self::assertSame('<idempotencyKey#2>', $replayedPlaceholders->normalizeOpaqueToken('idempotencyKey', '1111aaaa'));
    }

    public function testAMintedIdAfterAWordAndAnUnderscoreIsReplacedAndAnUnmintedOneStaysRaw(): void
    {
        $idPlaceholders = ToolCallComparisonIdPlaceholders::newInstance();
        $idPlaceholders->declareMintedIds(['EntityBuildRuns' => [2038]]);

        self::assertSame('{"externalId":"run_<EntityBuildRuns#1>"}', $idPlaceholders->normalizeText('{"externalId":"run_2038"}'));
        self::assertSame('{"externalId":"run_2039"}', $idPlaceholders->normalizeText('{"externalId":"run_2039"}'));
    }
}
