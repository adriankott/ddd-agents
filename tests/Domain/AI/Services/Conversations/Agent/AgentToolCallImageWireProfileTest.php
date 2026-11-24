<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\Conversations\Agent\Images\AgentToolCallImageStoreSettings;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The wire profile a captured picture is normalized to once: WebP (or JPEG) within the long edge and the megapixels,
 * the sha256 over the wire bytes; a picture already in a wire format within the limits keeps its bytes.
 */
class AgentToolCallImageWireProfileTest extends UnitTestCase
{
    public function testAPngBecomesWebpAndTheSha256IsTakenOverTheWireBytes(): void
    {
        $toolResultImage = ToolPictureFixtures::buildToolResultWithPicture()->images[0];
        $pngSha256 = $toolResultImage->sha256;

        self::assertTrue((new TieredAgentToolCallImageStoreServiceDouble())->normalizeToWireProfile($toolResultImage, new AgentToolCallImageStoreSettings()));

        $wireBytes = base64_decode($toolResultImage->base64Data, true);
        self::assertSame('image/webp', $toolResultImage->mimeType);
        self::assertSame('RIFF', substr((string)$wireBytes, 0, 4));
        self::assertSame(hash('sha256', (string)$wireBytes), $toolResultImage->sha256);
        self::assertNotSame($pngSha256, $toolResultImage->sha256);
        self::assertSame(strlen((string)$wireBytes), $toolResultImage->byteSize);
        self::assertSame([64, 48], [$toolResultImage->width, $toolResultImage->height]);
    }

    public function testALargePictureIsScaledToTheLongEdgeAndTheMegapixels(): void
    {
        $storeService = new TieredAgentToolCallImageStoreServiceDouble();
        $wide = ToolPictureFixtures::buildToolResultWithPicture(ToolPictureFixtures::buildPngBase64(3000, 600))->images[0];
        $square = ToolPictureFixtures::buildToolResultWithPicture(ToolPictureFixtures::buildPngBase64(2000, 2000))->images[0];

        self::assertTrue($storeService->normalizeToWireProfile($wide, new AgentToolCallImageStoreSettings()));
        self::assertTrue($storeService->normalizeToWireProfile($square, new AgentToolCallImageStoreSettings()));

        self::assertSame(2048, $wide->width);
        self::assertSame(409, $wide->height);
        self::assertLessThanOrEqual(3_750_000, $square->width * $square->height);
        self::assertSame(1936, $square->width);
    }

    public function testAJpegWithinTheLimitsKeepsItsBytes(): void
    {
        $jpegBase64 = ToolPictureFixtures::buildJpegBase64();
        $toolResultImage = ToolPictureFixtures::buildToolResultWithPicture($jpegBase64)->images[0];

        self::assertTrue((new TieredAgentToolCallImageStoreServiceDouble())->normalizeToWireProfile($toolResultImage, new AgentToolCallImageStoreSettings()));

        self::assertSame($jpegBase64, $toolResultImage->base64Data);
        self::assertSame('image/jpeg', $toolResultImage->mimeType);
    }

    public function testTheProfileOffKeepsThePngAndTheJpegFormatEncodesJpeg(): void
    {
        $storeService = new TieredAgentToolCallImageStoreServiceDouble();
        $pngBase64 = ToolPictureFixtures::buildPngBase64();
        $unchanged = ToolPictureFixtures::buildToolResultWithPicture($pngBase64)->images[0];
        $profileOff = AgentToolCallImageStoreSettings::fromConfigNode(['wireProfile' => ['enabled' => false]]);
        self::assertTrue($storeService->normalizeToWireProfile($unchanged, $profileOff));
        self::assertSame($pngBase64, $unchanged->base64Data);
        self::assertSame('image/png', $unchanged->mimeType);

        $asJpeg = ToolPictureFixtures::buildToolResultWithPicture($pngBase64)->images[0];
        self::assertTrue($storeService->normalizeToWireProfile($asJpeg, AgentToolCallImageStoreSettings::fromConfigNode(['wireProfile' => ['format' => 'jpg', 'quality' => 70]])));
        self::assertSame('image/jpeg', $asJpeg->mimeType);
        self::assertSame("\xFF\xD8\xFF", substr((string)base64_decode($asJpeg->base64Data, true), 0, 3));
    }

    public function testBytesThatAreNoPictureAreLeftAsTheyAre(): void
    {
        $toolResultImage = new ToolResultImage();
        $toolResultImage->base64Data = base64_encode('%!PS-Adobe-3.0 not a picture');
        $toolResultImage->mimeType = 'image/png';

        self::assertFalse((new TieredAgentToolCallImageStoreServiceDouble())->normalizeToWireProfile($toolResultImage, new AgentToolCallImageStoreSettings()));
        self::assertSame('image/png', $toolResultImage->mimeType);
    }
}
