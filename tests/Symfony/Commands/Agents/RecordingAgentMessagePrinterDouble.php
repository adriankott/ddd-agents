<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Services\Conversations\AgentMessagePrinterInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A printer that prints one line per message it is asked to render and records the texts.
 */
class RecordingAgentMessagePrinterDouble implements AgentMessagePrinterInterface
{
    /** @var string[] the texts of the rendered messages, in order */
    public array $renderedMessageTexts = [];

    public function renderMessage(
        AIConversationMessage $message,
        OutputInterface $output,
        bool $full = false,
        bool $debug = false,
        bool $collapseThreads = false,
        ?AIConversation $conversation = null
    ): void {
        $this->renderedMessageTexts[] = (string)$message->message;
        $output->writeln('rendered> ' . $message->message);
    }

    public function renderTranscript(AIConversation $conversation, bool $full = true, bool $debug = false, bool $collapseThreads = false): string
    {
        return implode("\n", $this->renderedMessageTexts);
    }
}
