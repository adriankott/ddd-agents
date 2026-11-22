<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\WebSearch\PageScreenshots\PageScreenshotPart;
use DDD\Presentation\Mcp\Client\AI\WebPageScreenshotPartsOfTheCurrentTurn;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The screenshot line names the parts of the same page and device already shown in the current turn: completed calls
 * only, the URL compared canonically, an earlier turn and the other device left out; a repeat gets a note, no refusal.
 * A next part is refused while the previous one was not written about.
 */
class WebPageScreenshotPartsOfTheCurrentTurnTest extends UnitTestCase
{
    public function testThePartsShownInThisTurnAreNamed(): void
    {
        $messages = new AIConversationMessages();
        $row = $this->buildUserMessage(0);
        $messages->add($row);
        $row = $this->buildScreenshotCall(1, ['url' => 'https://example.com/', 'device' => 'mobile', 'part' => 1], AIConversationMessage::STATUS_COMPLETED);
        $messages->add($row);
        $row = $this->buildUserMessage(2);
        $messages->add($row);
        $row = $this->buildScreenshotCall(3, ['url' => 'https://www.example.com', 'device' => 'mobile', 'part' => 2], AIConversationMessage::STATUS_COMPLETED);
        $messages->add($row);
        $row = $this->buildScreenshotCall(4, ['url' => 'https://example.com', 'device' => 'mobile'], AIConversationMessage::STATUS_COMPLETED);
        $messages->add($row);
        $row = $this->buildScreenshotCall(5, ['url' => 'https://example.com', 'device' => 'mobile', 'part' => 3], AIConversationMessage::STATUS_FAILED);
        $messages->add($row);
        $row = $this->buildScreenshotCall(6, ['url' => 'https://example.com', 'part' => 3], AIConversationMessage::STATUS_COMPLETED);
        $messages->add($row);
        $row = $this->buildScreenshotCall(7, ['url' => 'https://example.com', 'device' => 'mobile', 'part' => 1], AIConversationMessage::STATUS_PENDING);
        $messages->add($row);

        $shownPartNumbers = WebPageScreenshotPartsOfTheCurrentTurn::findPartNumbersShownInMessages($messages, 'https://example.com', PageScreenshotPart::DEVICE_MOBILE);
        self::assertSame([1, 2], $shownPartNumbers, 'part 1 of the earlier turn, the failed part 3, the desktop call and the pending call do not count; www and the slash are the same page');

        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com';
        $pageScreenshotPart->device = PageScreenshotPart::DEVICE_MOBILE;
        $pageScreenshotPart->partNumber = 1;
        $pageScreenshotPart->pageHeightPx = 9000;
        $pageScreenshotPart->partNumbersAlreadyShownInTurn = $shownPartNumbers;
        $coverageText = $pageScreenshotPart->toCoverageText();
        self::assertStringContainsString('Already shown in this turn (mobile): part=1, part=2.', $coverageText);
        self::assertStringContainsString('You already opened part=1 of this view in this turn', $coverageText);
        self::assertStringContainsString('never open a part you have already seen', $coverageText);
    }

    public function testWithTheCallIdEveryEarlierCallCountsAndTheCallingRowIsLeftOut(): void
    {
        $messages = new AIConversationMessages();
        $row = $this->buildUserMessage(0);
        $messages->add($row);
        // part 1's completion has not reached the read set yet: it still counts, the calling row (call_3) does not
        $row = $this->buildScreenshotCall(1, ['url' => 'https://example.com', 'device' => 'Mobile'], AIConversationMessage::STATUS_RUNNING, 'call_1');
        $messages->add($row);
        $row = $this->buildScreenshotCall(2, ['url' => 'https://example.com', 'device' => 'mobile', 'part' => 2], AIConversationMessage::STATUS_COMPLETED, 'call_2');
        $messages->add($row);
        $row = $this->buildScreenshotCall(3, ['url' => 'https://example.com', 'device' => 'mobile', 'part' => 3], AIConversationMessage::STATUS_PENDING, 'call_3');
        $messages->add($row);
        $row = $this->buildScreenshotCall(4, ['url' => 'https://example.com', 'device' => 'mobile', 'part' => 4], AIConversationMessage::STATUS_FAILED, 'call_4');
        $messages->add($row);

        self::assertSame([1, 2], WebPageScreenshotPartsOfTheCurrentTurn::findPartNumbersShownInMessages($messages, 'https://example.com', PageScreenshotPart::DEVICE_MOBILE, 'call_3'));
    }

    public function testTheLineNamesThePictureBudget(): void
    {
        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com';
        $pageScreenshotPart->pageHeightPx = 9000;
        $pageScreenshotPart->base64Images = ['a', 'b'];
        $pageScreenshotPart->picturesKeptInView = 2;
        self::assertStringContainsString('Only the newest 2 picture(s) stay in your view (this part has 2)', $pageScreenshotPart->toCoverageText());

        $pageScreenshotPart->picturesKeptInView = null;
        self::assertStringContainsString('Earlier screenshots leave your view when the picture budget is exceeded', $pageScreenshotPart->toCoverageText());
    }

    public function testTheLineAsksForTheDescriptionBeforeTheNextPartWhileTheBudgetHoldsOnePart(): void
    {
        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com';
        $pageScreenshotPart->pageHeightPx = 9000;
        $pageScreenshotPart->partNumber = 2;
        $pageScreenshotPart->base64Images = ['a', 'b'];
        $pageScreenshotPart->picturesKeptInView = 2;
        self::assertStringContainsString('Your NEXT message first writes what part 2 shows; the same message may then open part=3', $pageScreenshotPart->toCoverageText());
        self::assertStringContainsString('Your descriptions stay your record', $pageScreenshotPart->toCoverageText());

        $pageScreenshotPart->partNumber = 3;
        self::assertStringNotContainsString('Your NEXT message', $pageScreenshotPart->toCoverageText(), 'the last part has no next one');

        $pageScreenshotPart->partNumber = 1;
        $pageScreenshotPart->picturesKeptInView = 4;
        self::assertStringNotContainsString('Your NEXT message', $pageScreenshotPart->toCoverageText(), 'a budget of two parts lets the next part through');
    }

    public function testALongDescriptionThatStartsWithTheMarkerCounts(): void
    {
        $messages = new AIConversationMessages();
        $row = $this->buildUserMessage(0);
        $messages->add($row);
        $row = $this->buildScreenshotCall(1, ['url' => 'https://example.com'], AIConversationMessage::STATUS_COMPLETED, 'call_1');
        $messages->add($row);
        $row = $this->buildAssistantText(2, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' Part 1 shows a dark header with the logo on the left, a search field in the middle, a green call to action and a hero image of a bike.');
        $messages->add($row);
        $row = $this->buildScreenshotCall(3, ['url' => 'https://example.com', 'part' => 2], AIConversationMessage::STATUS_PENDING, 'call_3');
        $messages->add($row);
        self::assertNull(WebPageScreenshotPartsOfTheCurrentTurn::findUndescribedScreenshotCallInMessages($messages, 'call_3'));
    }

    public function testTheLastPartPointsToTheDescriptionsOfTheEarlierOnes(): void
    {
        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com';
        $pageScreenshotPart->pageHeightPx = 9000;
        $pageScreenshotPart->partNumber = 3;
        $pageScreenshotPart->base64Images = ['a'];
        $pageScreenshotPart->partNumbersAlreadyShownInTurn = [1, 2];
        self::assertStringContainsString('Your descriptions of part 1, 2 above are your record of them', $pageScreenshotPart->toCoverageText());

        $pageScreenshotPart->partNumber = 2;
        self::assertStringNotContainsString('are your record of them', $pageScreenshotPart->toCoverageText());
    }

    public function testNothingOutsideTheLoop(): void
    {
        self::assertSame([], WebPageScreenshotPartsOfTheCurrentTurn::findPartNumbersShownInTurn(new AgentToolCallProvenance(), 'https://example.com', PageScreenshotPart::DEVICE_DESKTOP));

        $pageScreenshotPart = new PageScreenshotPart();
        $pageScreenshotPart->url = 'https://example.com';
        $pageScreenshotPart->pageHeightPx = 3000;
        self::assertStringNotContainsString('Already shown', $pageScreenshotPart->toCoverageText());
    }

    public function testANextPartIsRefusedUntilTheModelWroteAboutThePreviousOne(): void
    {
        $messages = new AIConversationMessages();
        $row = $this->buildUserMessage(0);
        $messages->add($row);
        $row = $this->buildScreenshotCall(1, ['url' => 'https://example.com', 'device' => 'mobile'], AIConversationMessage::STATUS_COMPLETED, 'call_1');
        $messages->add($row);
        $row = $this->buildScreenshotCall(2, ['url' => 'https://example.com', 'device' => 'mobile', 'part' => 2], AIConversationMessage::STATUS_PENDING, 'call_2');
        $messages->add($row);

        $undescribedCall = WebPageScreenshotPartsOfTheCurrentTurn::findUndescribedScreenshotCallInMessages($messages, 'call_2');
        self::assertNotNull($undescribedCall, 'part 1 came back and no text followed it');
        $refusalText = WebPageScreenshotPartsOfTheCurrentTurn::buildDescribeFirstRefusalText($undescribedCall);
        self::assertStringContainsString('describe part 1 of https://example.com first', $refusalText);
        self::assertStringContainsString('Do not reopen part 1', $refusalText);

        // a status line is no description
        $messages = new AIConversationMessages();
        $row = $this->buildUserMessage(0);
        $messages->add($row);
        $row = $this->buildScreenshotCall(1, ['url' => 'https://example.com', 'part' => 2], AIConversationMessage::STATUS_COMPLETED, 'call_1');
        $messages->add($row);
        $row = $this->buildAssistantText(2, AIConversationMessage::PRE_CALL_MESSAGE_MARKER . ' Opening part 3');
        $messages->add($row);
        $row = $this->buildScreenshotCall(3, ['url' => 'https://example.com', 'part' => 3], AIConversationMessage::STATUS_PENDING, 'call_3');
        $messages->add($row);
        self::assertSame($messages->getElements()[1] ?? null, WebPageScreenshotPartsOfTheCurrentTurn::findUndescribedScreenshotCallInMessages($messages, 'call_3'));
    }

    public function testAPartFollowsWhenTheModelWroteAboutThePreviousOneOrItFailed(): void
    {
        $messages = new AIConversationMessages();
        $row = $this->buildScreenshotCall(0, ['url' => 'https://example.com'], AIConversationMessage::STATUS_COMPLETED, 'call_0');
        $messages->add($row);
        $row = $this->buildUserMessage(1);
        $messages->add($row);
        $row = $this->buildScreenshotCall(2, ['url' => 'https://example.com'], AIConversationMessage::STATUS_COMPLETED, 'call_2');
        $messages->add($row);
        $row = $this->buildAssistantText(3, 'Part 1 shows a dark header with the logo left and a green call to action.');
        $messages->add($row);
        $row = $this->buildScreenshotCall(4, ['url' => 'https://example.com', 'part' => 2], AIConversationMessage::STATUS_FAILED, 'call_4');
        $messages->add($row);
        $row = $this->buildScreenshotCall(5, ['url' => 'https://example.com', 'part' => 2], AIConversationMessage::STATUS_PENDING, 'call_5');
        $messages->add($row);

        self::assertNull(
            WebPageScreenshotPartsOfTheCurrentTurn::findUndescribedScreenshotCallInMessages($messages, 'call_5'),
            'the earlier turn does not count, part 1 was described, the failed part 2 showed nothing'
        );
        self::assertNull(WebPageScreenshotPartsOfTheCurrentTurn::findUndescribedScreenshotCallOfTurn(new AgentToolCallProvenance()));
    }

    protected function buildAssistantText(int $index, string $text): AIConversationMessage
    {
        $message = $this->buildUserMessage($index);
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->message = $text;
        return $message;
    }

    protected function buildUserMessage(int $index): AIConversationMessage
    {
        /** @var AIConversationMessage $message */
        $message = self::instantiateWithoutConstructor(AIConversationMessage::class);
        $message->role = AIConversationMessage::ROLE_USER;
        $message->authorType = AIConversationMessage::AUTHOR_USER;
        $message->messageType = AIConversationMessage::MESSAGE_TEXT;
        $message->message = 'how does it look';
        $message->indexInConversation = $index;
        return $message;
    }

    /** @param array<string, mixed> $arguments */
    protected function buildScreenshotCall(int $index, array $arguments, string $status, ?string $toolCallId = null): AIConversationMessage
    {
        $message = $this->buildUserMessage($index);
        $message->role = AIConversationMessage::ROLE_ASSISTANT;
        $message->authorType = AIConversationMessage::AUTHOR_ASSISTANT;
        $message->messageType = AIConversationMessage::MESSAGE_TOOL_CALL;
        /** @var ToolCall $toolCall */
        $toolCall = self::instantiateWithoutConstructor(ToolCall::class);
        $toolCall->name = WebPageScreenshotPartsOfTheCurrentTurn::TOOL_NAME;
        $toolCall->status = $status;
        $toolCall->arguments = $arguments;
        $toolCall->toolCallId = $toolCallId;
        $message->toolCall = $toolCall;
        return $message;
    }
}
