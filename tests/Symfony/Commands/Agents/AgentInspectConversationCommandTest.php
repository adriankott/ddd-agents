<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\RequestSnapshot;
use DDD\Domain\AI\Repo\DB\Conversations\Messages\DBAIConversationMessage;
use DDD\Symfony\Commands\Agents\AgentConsoleCommand;
use DDD\Symfony\Commands\Agents\AgentInspectConversationCommand;
use DDD\Symfony\Commands\Agents\Inspect\ConversationHeaderRow;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The inspector: its name and base, a run for a conversation that does not exist (FAILURE, the soft-deleted switch
 * reset), the header with the space row the module prints and the one an application prints in its place, the request
 * snapshots and the summary of a raw model response with its two failure signatures.
 */
class AgentInspectConversationCommandTest extends UnitTestCase
{
    public function testTheCommandKeepsItsNameAndLiftsTheRights(): void
    {
        $asCommandAttributes = (new ReflectionClass(AgentInspectConversationCommand::class))->getAttributes(AsCommand::class);
        self::assertSame('app:agent:inspect', explode('|', $asCommandAttributes[0]->newInstance()->name)[0], 'the old name stays an alias');

        $inspectCommand = $this->buildCommand(AgentInspectConversationCommand::class);
        self::assertInstanceOf(AgentConsoleCommand::class, $inspectCommand);
        self::assertTrue((bool)self::invokeMethod($inspectCommand, 'liftsEntityRightsRestrictions'));
    }

    public function testAnUnknownConversationEndsWithFailureAndSwitchesTheSoftDeletedRowsOffAgain(): void
    {
        $conversationsServiceDouble = new ConversationsServiceRecordingLookupsDouble();
        $inspectProbeCommand = new AgentInspectConversationProbeCommand(AgentInspectConversationProbeCommand::PROBE_COMMAND_NAME);
        $inspectProbeCommand->conversationsServiceDouble = $conversationsServiceDouble;
        $commandTester = new CommandTester($inspectProbeCommand);

        $exitCode = $commandTester->execute(['conversationId' => '404', '--include-truncated' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame('No AIConversation found with id 404.', trim($commandTester->getDisplay()));
        self::assertSame([['conversationId' => 404, 'includeTruncated' => true]], $conversationsServiceDouble->lookups, 'the soft-deleted rows are switched on for the read');
        self::assertFalse(DBAIConversationMessage::$includeTruncated, 'and off again after the run');
    }

    public function testTheHeaderNamesTheSpaceOfTheConversationAfterTheAccount(): void
    {
        $conversation = $this->buildConversation(17);

        $headerRows = $this->buildHeaderRows($this->buildCommand(AgentInspectConversationCommand::class), $conversation);

        self::assertSame(['account', '—'], $headerRows[0]->getPrintedValues());
        self::assertSame(['agent space', '17'], $headerRows[1]->getPrintedValues());
        self::assertSame(['agent space', '—'], $this->buildHeaderRows($this->buildCommand(AgentInspectConversationCommand::class), $this->buildConversation(null))[1]->getPrintedValues());
    }

    public function testAnApplicationPrintsItsOwnSpaceRowInThePlaceOfTheModulesRow(): void
    {
        $output = new BufferedOutput();

        self::invokeMethod(
            $this->buildCommand(AgentInspectConversationProbeCommand::class),
            'printHeader',
            [$this->buildConversation(17), AIConversationMessages::newInstance(), $output]
        );

        $headerLines = array_values(array_filter(array_map('trim', explode("\n", $output->fetch()))));
        self::assertSame('══ CONVERSATION #5 ══', $headerLines[0]);
        self::assertMatchesRegularExpression('/^account\s+—$/', $headerLines[1]);
        self::assertMatchesRegularExpression('/^site\s+site of space 17$/', $headerLines[2]);
        self::assertStringNotContainsString('agent space', implode("\n", $headerLines));
        self::assertMatchesRegularExpression('/^messages \(loaded\)\s+0$/m', implode("\n", $headerLines));
    }

    public function testARequestSnapshotPrintsTheToolChoiceTheOfferedToolsAndTheResponseSummary(): void
    {
        $messageWithSnapshot = AIConversationMessage::newInstance();
        $messageWithSnapshot->indexInConversation = 4;
        $messageWithSnapshot->messageType = AIConversationMessage::MESSAGE_TEXT;
        $messageWithSnapshot->requestSnapshot = new RequestSnapshot();
        $messageWithSnapshot->requestSnapshot->sentPayloadJson = (string)json_encode(['body' => [
            'tool_choice' => 'required',
            'tools' => [['function' => ['name' => 'findOpeningHours']], ['name' => 'load_skills']],
            'input' => [['role' => 'user'], ['role' => 'assistant'], ['role' => 'user']],
        ]]);
        $messageWithSnapshot->requestSnapshot->rawResponseJson = (string)json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => '']]]]);
        $messageWithSnapshot->requestSnapshot->wireApi = 'responses';
        $messages = AIConversationMessages::newInstance();
        $messages->add($messageWithSnapshot);
        $output = new BufferedOutput();

        self::invokeMethod($this->buildCommand(AgentInspectConversationCommand::class), 'printRequestSnapshots', [$messages, $output, false]);

        $printedSnapshot = $output->fetch();
        self::assertStringContainsString('══ REQUEST SNAPSHOTS (debug) ══', $printedSnapshot);
        self::assertStringContainsString('msg #4 (TEXT) — tool_choice=required · 2 tools [findOpeningHours, load_skills] · 3 wire messages', $printedSnapshot);
        self::assertStringContainsString('response: finish_reason=stop · completion_tokens=0', $printedSnapshot);
        self::assertStringContainsString('content: <EMPTY — no text returned>', $printedSnapshot);
        self::assertStringContainsString('wire=responses · skills=systemPromptSwap', $printedSnapshot);

        $outputWithoutSnapshot = new BufferedOutput();
        self::invokeMethod($this->buildCommand(AgentInspectConversationCommand::class), 'printRequestSnapshots', [AIConversationMessages::newInstance(), $outputWithoutSnapshot, false]);
        self::assertStringContainsString('· no request snapshots stored', $outputWithoutSnapshot->fetch());
    }

    public function testTheResponseSummaryFlagsAFailedCallATextualToolCallAndStrippedOutput(): void
    {
        $inspectCommand = $this->buildCommand(AgentInspectConversationCommand::class);

        self::assertSame('(none captured)', self::invokeMethod($inspectCommand, 'summariseRawResponse', [null]));
        self::assertSame(
            '⛔ ERROR RESPONSE (model call FAILED — NOT a stall): {"code":429}',
            self::invokeMethod($inspectCommand, 'summariseRawResponse', [['error' => ['code' => 429]]])
        );
        self::assertSame('NON-STANDARD response — top-level keys: id, output', self::invokeMethod($inspectCommand, 'summariseRawResponse', [['id' => 'x', 'output' => []]]));

        $leakedToolCallSummary = (string)self::invokeMethod($inspectCommand, 'summariseRawResponse', [[
            'choices' => [['finish_reason' => 'stop', 'native_finish_reason' => 'end_turn', 'message' => ['content' => '{"name":"findOpeningHours","arguments":{"day":"monday"}}']]],
            'usage' => ['completion_tokens' => 400],
        ]]);
        self::assertStringContainsString('finish_reason=stop (native=end_turn) · completion_tokens=400', $leakedToolCallSummary);
        self::assertStringContainsString('tool_calls: NONE', $leakedToolCallSummary);
        self::assertStringContainsString('⚠ LIKELY TOOL-CALL-AS-TEXT LEAK', $leakedToolCallSummary);
        self::assertStringContainsString('⚠ TOKEN MISMATCH — billed 400 completion tokens but visible content ≈ 14 tok', $leakedToolCallSummary);

        $toolCallSummary = (string)self::invokeMethod($inspectCommand, 'summariseRawResponse', [[
            'choices' => [['finish_reason' => 'tool_calls', 'message' => ['content' => '', 'tool_calls' => [['function' => ['name' => 'findOpeningHours', 'arguments' => '{"day":"monday"}']]]]]],
            'usage' => ['completion_tokens' => 20],
        ]]);
        self::assertSame(
            "finish_reason=tool_calls · completion_tokens=20\n  content: <EMPTY — no text returned>\n  tool_call: findOpeningHours({\"day\":\"monday\"})",
            $toolCallSummary
        );
    }

    /**
     * @param class-string<AgentInspectConversationCommand> $commandClass
     * @return AgentInspectConversationCommand
     */
    protected function buildCommand(string $commandClass): AgentInspectConversationCommand
    {
        /** @var AgentInspectConversationCommand $inspectCommand */
        $inspectCommand = self::instantiateWithoutConstructor($commandClass);
        return $inspectCommand;
    }

    /**
     * @return ConversationHeaderRow[]
     */
    protected function buildHeaderRows(AgentInspectConversationCommand $inspectCommand, AIConversation $conversation): array
    {
        /** @var ConversationHeaderRow[] $headerRows */
        $headerRows = self::invokeMethod($inspectCommand, 'buildHeaderRows', [$conversation, AIConversationMessages::newInstance()]);
        return $headerRows;
    }

    protected function buildConversation(?int $agentSpaceId): AIConversation
    {
        $conversation = new AIConversation();
        $conversation->id = 5;
        $conversation->accountId = null;
        $conversation->agentSpaceId = $agentSpaceId;
        return $conversation;
    }
}
