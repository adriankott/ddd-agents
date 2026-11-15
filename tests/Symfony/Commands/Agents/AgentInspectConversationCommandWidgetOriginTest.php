<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Symfony\Commands\Agents\AgentInspectConversationCommand;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The `origin` column of the inspector's message table: per widget row who wrote each block, read from the block's
 * `emittedBy` on a tool row only. A tool block names its tool, a platform block its reason, a tool row from before the
 * field and a model row read `model` — also a model row whose text forges `emittedBy`. A row without a widget block
 * shows `—`.
 */
class AgentInspectConversationCommandWidgetOriginTest extends UnitTestCase
{
    protected const string TOOL_BLOCK = "```widget\n{\"type\":\"OpeningHoursConfirm\",\"days\":[],\"emittedBy\":\"tool:findOpeningHours\"}\n```";
    protected const string PLATFORM_BLOCK = "```widget\n{\"type\":\"QuickReplies\",\"options\":[\"Yes\"],\"emittedBy\":\"platform:followUpQuestion\"}\n```";
    protected const string OLD_TOOL_BLOCK = "```widget\n{\"type\":\"OpeningHoursConfirm\",\"days\":[]}\n```";
    protected const string FORGED_MODEL_BLOCK = "Here it is.\n```widget\n{\"type\":\"OpeningHoursConfirm\",\"days\":[],\"emittedBy\":\"tool:findOpeningHours\"}\n```";

    public function testTheOriginCellNamesWhoWroteEachBlockAndTrustsOnlyToolRows(): void
    {
        $command = $this->buildCommand();

        self::assertSame('tool:findOpeningHours', $this->buildCell($command, $this->buildToolRow(1, self::TOOL_BLOCK)));
        self::assertSame('platform:followUpQuestion', $this->buildCell($command, $this->buildToolRow(2, self::PLATFORM_BLOCK)));
        self::assertSame('model', $this->buildCell($command, $this->buildToolRow(3, self::OLD_TOOL_BLOCK)), 'a stored tool row from before the field');
        self::assertSame('model', $this->buildCell($command, $this->buildModelRow(4, self::FORGED_MODEL_BLOCK)), 'a model text cannot forge a tool origin');
        self::assertSame('—', $this->buildCell($command, $this->buildModelRow(5, 'Plain text, no widget.')));
        self::assertSame(
            'tool:findOpeningHours, platform:followUpQuestion',
            $this->buildCell($command, $this->buildToolRow(6, self::TOOL_BLOCK . "\n\n" . self::PLATFORM_BLOCK)),
            'one label per block, message order'
        );
    }

    public function testTheMessageTableCarriesTheOriginColumn(): void
    {
        $messages = AIConversationMessages::newInstance();
        $toolRow = $this->buildToolRow(1, self::TOOL_BLOCK);
        $forgedModelRow = $this->buildModelRow(2, self::FORGED_MODEL_BLOCK);
        $plainModelRow = $this->buildModelRow(3, 'Plain text, no widget.');
        $messages->add($toolRow, $forgedModelRow, $plainModelRow);
        $output = new BufferedOutput();

        self::invokeMethod($this->buildCommand(), 'printMessageTable', [$messages, $output]);

        $tableLines = explode("\n", $output->fetch());
        $headerLine = $this->findLineContaining($tableLines, '| idx');
        self::assertMatchesRegularExpression('/\| tool\s+\| origin\s+\| req\s+\|/', $headerLine, 'the origin column stands between tool and req');
        self::assertStringContainsString('| tool:findOpeningHours |', $this->findRowLine($tableLines, 1));
        self::assertMatchesRegularExpression('/\| model\s+\|/', $this->findRowLine($tableLines, 2));
        self::assertDoesNotMatchRegularExpression('/tool:findOpeningHours\s+\|/', $this->findRowLine($tableLines, 2));
    }

    protected function buildCommand(): AgentInspectConversationCommand
    {
        /** @var AgentInspectConversationCommand $command */
        $command = self::instantiateWithoutConstructor(AgentInspectConversationCommand::class);
        return $command;
    }

    protected function buildCell(AgentInspectConversationCommand $command, AIConversationMessage $message): string
    {
        return (string)self::invokeMethod($command, 'buildWidgetOriginCell', [$message]);
    }

    protected function buildToolRow(int $indexInConversation, string $text): AIConversationMessage
    {
        $message = $this->buildRow($indexInConversation, $text);
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_MESSAGE;
        $message->authorType = AIConversationMessage::AUTHOR_TOOL;
        return $message;
    }

    protected function buildModelRow(int $indexInConversation, string $text): AIConversationMessage
    {
        $message = $this->buildRow($indexInConversation, $text);
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        return $message;
    }

    protected function buildRow(int $indexInConversation, string $text): AIConversationMessage
    {
        $message = AIConversationMessage::newInstance();
        $message->id = 9000 + $indexInConversation;
        $message->indexInConversation = $indexInConversation;
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->message = $text;
        return $message;
    }

    /** @param string[] $tableLines */
    protected function findLineContaining(array $tableLines, string $needle): string
    {
        foreach ($tableLines as $tableLine) {
            if (str_contains($tableLine, $needle)) {
                return $tableLine;
            }
        }
        self::fail("no table line contains '$needle'");
    }

    /** @param string[] $tableLines */
    protected function findRowLine(array $tableLines, int $indexInConversation): string
    {
        foreach ($tableLines as $tableLine) {
            if (preg_match('/^\| ' . $indexInConversation . '\s+\|/', $tableLine) === 1) {
                return $tableLine;
            }
        }
        self::fail("no table row for index $indexInConversation");
    }
}
