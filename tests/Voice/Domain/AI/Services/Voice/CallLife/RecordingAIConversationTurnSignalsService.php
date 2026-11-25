<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Domain\AI\Services\Voice\CallLife;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\OwnerDevice;
use DDD\Domain\AI\Entities\Conversations\Messages\AgentClientContext;
use DDD\Domain\AI\Services\Conversations\Agent\AIConversationTurnSignalsService;
use DDD\Infrastructure\Services\AuthService;
use RuntimeException;

/**
 * The signal entry of the package, recording each injected signal with the account signed in at that moment;
 * with {@see $failureMessage} set it throws after recording.
 */
class RecordingAIConversationTurnSignalsService extends AIConversationTurnSignalsService
{
    /** @var array<int, array{conversationId: int, signalText: string, signalSource: string, async: bool, signedInAccountId: ?int}> */
    public array $injectedSignals = [];

    public ?string $failureMessage = null;

    public function injectSignal(
        AIConversation $conversation,
        string $signalText,
        string $signalSource = AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK,
        bool $async = true,
        ?OwnerDevice $ownerDevice = null,
        ?AgentClientContext $clientContext = null
    ): AIConversationMessage {
        $signedInAccount = AuthService::instance()->getAccount();
        $this->injectedSignals[] = [
            'conversationId' => (int)$conversation->id,
            'signalText' => $signalText,
            'signalSource' => $signalSource,
            'async' => $async,
            'signedInAccountId' => $signedInAccount !== null ? (int)$signedInAccount->id : null,
        ];
        if ($this->failureMessage !== null) {
            throw new RuntimeException($this->failureMessage);
        }

        return AIConversationMessage::newInstance();
    }
}
