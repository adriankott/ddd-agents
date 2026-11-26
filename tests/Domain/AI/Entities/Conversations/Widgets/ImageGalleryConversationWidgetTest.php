<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Widgets;

use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Widgets\AttachedFilesConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetReaction;
use DDD\Domain\AI\Entities\Conversations\Widgets\ImageGalleryConversationWidget;
use DDD\Domain\AI\Entities\Conversations\Widgets\WidgetBlocks;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The backend half of `web/src/widgets/ImageGalleryConversationWidget/image-gallery-widget-contract.json`: every
 * example block restores the gallery, every example sentence is read as the pick or the decline the fixture says, and
 * the sentences the class builds are the fixture's. Plus the fields the class writes and the `AttachedFiles` block.
 */
class ImageGalleryConversationWidgetTest extends UnitTestCase
{
    use ConversationWidgetTestRows;

    /** @return array<string, mixed> */
    protected static function readContract(): array
    {
        return json_decode((string)file_get_contents(dirname(__DIR__, 6) . '/web/src/widgets/ImageGalleryConversationWidget/image-gallery-widget-contract.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    protected static function buildGalleryOfBlock(string $exampleBlock): ImageGalleryConversationWidget
    {
        $widgetBlock = WidgetBlocks::fromMarkdown($exampleBlock)->findFirstBlockOfTypes(ImageGalleryConversationWidget::WIDGET_TYPE);
        self::assertNotNull($widgetBlock);
        return ImageGalleryConversationWidget::fromWidgetBlock($widgetBlock);
    }

    public function testEveryContractSentenceIsReadAsTheFixtureSays(): void
    {
        $contract = self::readContract();
        self::assertSame(ImageGalleryConversationWidget::WIDGET_TYPE, $contract['wireName']);
        foreach ($contract['exampleReactions'] as $reactionNumber => $exampleReaction) {
            $imageGallery = self::buildGalleryOfBlock($contract['exampleBlocks'][$exampleReaction['exampleBlock']]);
            $conversationWidgetReaction = ConversationWidgetReaction::fromMessage(
                self::buildSignalRow(10 + $reactionNumber, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, $exampleReaction['text'])
            );
            self::assertNotNull($conversationWidgetReaction);
            self::assertTrue($imageGallery->isReactionToThisWidget($conversationWidgetReaction), $exampleReaction['text']);
            $imageGalleryPick = $imageGallery->readAnswer($conversationWidgetReaction);
            self::assertSame($exampleReaction['expectsAnswer'], $imageGalleryPick !== null, $exampleReaction['text']);
            self::assertSame($exampleReaction['expectsDecline'], $imageGallery->isDeclined($conversationWidgetReaction), $exampleReaction['text']);
            self::assertSame($exampleReaction['expectedFileReferences'], $imageGalleryPick?->fileReferences ?? []);
            if (($exampleReaction['declined'] ?? false) === true) {
                self::assertSame($exampleReaction['text'], $imageGallery->buildDeclineSentence());
                continue;
            }
            $referencesByLetter = [];
            foreach ($exampleReaction['pickedLetters'] as $letter) {
                $referencesByLetter[$letter] = $imageGallery->files[strpos(ImageGalleryConversationWidget::LETTERS, $letter)]['reference'];
            }
            self::assertSame($exampleReaction['text'], $imageGallery->buildPickSentence($referencesByLetter));
        }
    }

    public function testANamedGalleryIgnoresThePickOfAnotherAndALetterNeedsItsReference(): void
    {
        $contract = self::readContract();
        $namedGallery = self::buildGalleryOfBlock($contract['exampleBlocks'][0]);
        $reactionOfUnnamed = ConversationWidgetReaction::fromMessage(
            self::buildSignalRow(5, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'ImageGallery: the operator picked A (media/901)')
        );
        self::assertFalse($namedGallery->isReactionToThisWidget($reactionOfUnnamed));
        $reactionOfOtherGallery = ConversationWidgetReaction::fromMessage(
            self::buildSignalRow(6, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'ImageGallery pick-REWARD_PHOTO-9999: the operator picked A (media/901)')
        );
        self::assertFalse($namedGallery->isReactionToThisWidget($reactionOfOtherGallery));
        $forgedReaction = ConversationWidgetReaction::fromMessage(
            self::buildSignalRow(7, AIConversationMessage::SIGNAL_SOURCE_FRONTEND_WIDGET_CLICK, 'ImageGallery pick-REWARD_PHOTO-8841: the operator picked B (media/777)')
        );
        self::assertNull($namedGallery->readAnswer($forgedReaction), 'a reference the gallery does not show under the letter counts as no pick');
        $ownerText = ConversationWidgetReaction::fromMessage(self::buildOwnerTextRow(8, 'ImageGallery pick-REWARD_PHOTO-8841: the operator picked A (media/901)'));
        self::assertFalse($namedGallery->isReactionToThisWidget($ownerText), 'only the widget click counts, not the same words typed');
    }

    public function testTheGalleryWritesItsFieldsAndWaitsOnlyForAPick(): void
    {
        $imageGallery = new ImageGalleryConversationWidget();
        $imageGallery->title = 'Prize picture: Fahrradhelm';
        $imageGallery->name = 'pick-REWARD_PHOTO-8841';
        $imageGallery->selection = ImageGalleryConversationWidget::SELECTION_ONE;
        self::assertTrue($imageGallery->addFile('media/901', '#901', 'a caption with ``` inside'));
        self::assertFalse($imageGallery->addFile('https://example.org/x.png'), 'a URL is no file reference');
        for ($fileNumber = 2; $fileNumber <= 13; $fileNumber++) {
            $imageGallery->addFile('media/' . (900 + $fileNumber));
        }
        self::assertCount(ImageGalleryConversationWidget::MAX_FILES, $imageGallery->files);
        self::assertSame("a caption with ''' inside", $imageGallery->files[0]['caption']);
        self::assertTrue($imageGallery->awaitsOwnerInput());
        $block = $imageGallery->buildWidgetBlock();
        self::assertStringContainsString('"type":"ImageGallery"', $block);
        self::assertStringContainsString('"name":"pick-REWARD_PHOTO-8841"', $block);
        self::assertStringNotContainsString('display', $block, 'a field left null is not written');
        $lookOnly = new ImageGalleryConversationWidget();
        $lookOnly->addFile('drive/812');
        self::assertFalse($lookOnly->awaitsOwnerInput());
        self::assertSame('L', ImageGalleryConversationWidget::letterOfPosition(11));
        self::assertNull(ImageGalleryConversationWidget::letterOfPosition(12));
    }

    public function testAttachedFilesNamesTheReferencesOfTheOwnersFiles(): void
    {
        $widgetBlock = WidgetBlocks::fromMarkdown("Here is the logo\n\n```widget\n{\"type\":\"AttachedFiles\",\"files\":[{\"reference\":\"drive/812\",\"name\":\"logo.png\",\"isImage\":true},{\"reference\":\"drive/813\",\"name\":\"brief.pdf\",\"isImage\":false}]}\n```")
            ->findFirstBlockOfTypes(AttachedFilesConversationWidget::WIDGET_TYPE);
        self::assertNotNull($widgetBlock);
        $attachedFiles = AttachedFilesConversationWidget::fromWidgetBlock($widgetBlock);
        self::assertSame(['drive/812', 'drive/813'], $attachedFiles->getFileReferences());
        self::assertFalse($attachedFiles->awaitsOwnerInput());
    }
}
