<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonCaseOutcome;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonDefinitionsDump;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonIdPlaceholders;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons\ToolCallComparisonRecording;
use DDD\Domain\Base\Entities\StaticRegistry;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes\ComparisonNotesMCPBundle;
use DDD\Tests\Agents\Fixtures\ToolCallComparisons\Host\Presentation\Mcp\Notes\ComparisonNotesMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The stored documents of the comparison keep their format: the recording writes its keys in the order the command has
 * written them since version 3 (`harness`, `version`, the script, the package, the run's agent space, the harness ids,
 * the pinned moment, the seed, the conversation ids, the dump's name, the outcomes, the Argus calls, the calls of the
 * application's recorders), each outcome in the order of its stored fields, and an application that maps a field to
 * another key gets that key at the same position, reads it back and writes the same bytes again. The definitions dump
 * names its tool fields the same way.
 */
class ToolCallComparisonStoredKeysTest extends UnitTestCase
{
    /** @var string[] The stored keys of a recording in their order, as an application maps them */
    public const array MAPPED_RECORDING_KEYS_IN_ORDER = [
        'harness', 'version', 'scriptName', 'scriptHash', 'packageClass', 'spaceIdOfTheRun', 'harnessIdsByPlaceholder',
        'pinnedMoment', 'idSeed', 'recordedConversationIds', 'definitionsDumpFileName', 'caseOutcomes', 'argusCalls',
        'backEndCalls',
    ];

    /** @var string[] The stored keys of a case outcome in their order, as an application maps them */
    public const array MAPPED_OUTCOME_KEYS_IN_ORDER = [
        'caseId', 'toolName', 'resultContent', 'isError', 'refusalKind', 'executionError', 'appendedRowsByConversation',
        'writeStatements', 'dispatches', 'backEndRequests', 'argusRequests', 'argusCallCount', 'statementAuditGap',
    ];

    public function testTheRecordingWritesItsKeysInTheirOrderUnderTheDefaultNames(): void
    {
        $storedFields = json_decode(ToolCallComparisonRecording::newInstance()->buildStoredJson(), true);

        self::assertSame(ToolCallComparisonRecording::STORED_FIELDS_IN_ORDER, array_keys($storedFields));
        self::assertSame(ToolCallComparisonRecording::HARNESS, $storedFields['harness']);
        self::assertSame(3, $storedFields['version']);
    }

    public function testAnApplicationsKeysAreWrittenAtTheirPositionsReadBackAndWrittenByteIdentically(): void
    {
        $frameworkToAppClassNamesBefore = StaticRegistry::$frameworkToAppClassNames;
        StaticRegistry::$frameworkToAppClassNames[ToolCallComparisonCaseOutcome::class] = MappedKeysToolCallComparisonCaseOutcome::class;
        try {
            $mappedKeysRecording = MappedKeysToolCallComparisonRecording::newInstance();
            $mappedKeysRecording->comparisonAgentSpaceId = 11111111;
            $hostRecording = new ToolCallComparisonHostRecordingDouble('[{"label":"c","request":{"method":"GET","endpoint":"/space/1"}}]');
            $mappedKeysRecording->keepHostRecordedCallsOf($hostRecording);
            $caseOutcome = ToolCallComparisonCaseOutcome::forCase('c', 'recordComparisonNote');
            $caseOutcome->keepHostRequestsOf($hostRecording, ToolCallComparisonIdPlaceholders::newInstance());
            $mappedKeysRecording->caseOutcomes->add($caseOutcome);

            $storedJson = $mappedKeysRecording->buildStoredJson();
            $storedFields = json_decode($storedJson, true);
            self::assertSame(self::MAPPED_RECORDING_KEYS_IN_ORDER, array_keys($storedFields));
            self::assertSame(self::MAPPED_OUTCOME_KEYS_IN_ORDER, array_keys($storedFields['caseOutcomes'][0]));
            self::assertSame(11111111, $storedFields['spaceIdOfTheRun']);

            $readRecording = MappedKeysToolCallComparisonRecording::fromJson($storedJson, 'recording.json');
            self::assertSame(11111111, $readRecording->comparisonAgentSpaceId);
            self::assertSame(1, $readRecording->countHostRecordedCalls());
            self::assertSame(1, $readRecording->caseOutcomes->findOutcomeOfCase('c')?->countHostRequests());
            self::assertSame($storedJson, $readRecording->buildStoredJson(), 'read and written again, the bytes are the same');
            self::assertSame('back-end request', $readRecording->caseOutcomes->findOutcomeOfCase('c')?->getHostRequestLabel());
        } finally {
            StaticRegistry::$frameworkToAppClassNames = $frameworkToAppClassNamesBefore;
        }
    }

    public function testTheDefinitionsDumpNamesItsToolFieldsByTheMap(): void
    {
        $defaultDump = ToolCallComparisonDefinitionsDump::buildForPackage(ComparisonNotesMCPPackage::class, [ComparisonNotesMCPBundle::class], ['agentSpaceId' => 'OPTIONAL — omit it. ']);
        $mappedKeysDump = MappedKeysToolCallComparisonDefinitionsDump::buildForPackage(ComparisonNotesMCPPackage::class, [ComparisonNotesMCPBundle::class], ['agentSpaceId' => 'OPTIONAL — omit it. ']);

        $defaultSections = json_decode($defaultDump->buildStoredJson(), true);
        $mappedSections = json_decode($mappedKeysDump->buildStoredJson(), true);

        self::assertSame(ToolCallComparisonDefinitionsDump::STORED_SECTIONS_IN_ORDER, array_keys($defaultSections));
        self::assertSame(ToolCallComparisonDefinitionsDump::STORED_TOOL_FIELDS_IN_ORDER, array_keys($defaultSections['tools']['recordComparisonNote']));
        self::assertSame('loopSchemaSpaceBound', array_keys($mappedSections['tools']['recordComparisonNote'])[0]);
        self::assertSame($defaultSections['tools']['recordComparisonNote']['loopSchemaAgentSpaceBound'], $mappedSections['tools']['recordComparisonNote']['loopSchemaSpaceBound']);
        self::assertSame([ComparisonNotesMCPBundle::class], $defaultSections['httpBundles']);
        self::assertNotNull($defaultSections['httpDocumentation'], 'a listed package carries its HTTP documentation');
        self::assertSame("\n", substr($defaultDump->buildStoredJson(), -1));
    }

    public function testTheDefinitionsDifferencesNameTheToolFieldUnderItsStoredKey(): void
    {
        $mappedKeysDump = MappedKeysToolCallComparisonDefinitionsDump::buildForPackage(ComparisonNotesMCPPackage::class, [], []);
        $recordedJson = $mappedKeysDump->buildStoredJson();
        $replayedSections = json_decode($recordedJson, true);
        $replayedSections['tools']['recordComparisonNote']['summary'] = 'Record two notes.';
        $replayedJson = (string)json_encode($replayedSections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

        self::assertSame([], $mappedKeysDump->buildDifferences($recordedJson, $recordedJson));
        self::assertSame(['definitions: recordComparisonNote.summary differs'], $mappedKeysDump->buildDifferences($recordedJson, $replayedJson));
        self::assertSame(['definitions: the files differ in their bytes (formatting or key order)'], $mappedKeysDump->buildDifferences($recordedJson, rtrim($recordedJson) . "\n\n"));
    }
}
