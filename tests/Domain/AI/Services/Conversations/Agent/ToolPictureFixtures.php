<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent;

use DDD\Domain\AI\Entities\MCPPackages\ToolResult;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;

/** Real pictures for the picture-store tests, drawn with GD (a two-colour gradient, so an encoder has work to do). */
class ToolPictureFixtures
{
    public static function buildPngBase64(int $width = 64, int $height = 48): string
    {
        ob_start();
        imagepng(static::drawPicture($width, $height));
        return base64_encode((string)ob_get_clean());
    }

    public static function buildJpegBase64(int $width = 64, int $height = 48): string
    {
        ob_start();
        imagejpeg(static::drawPicture($width, $height), null, 90);
        return base64_encode((string)ob_get_clean());
    }

    /** A tool result with one inline picture of the tool. */
    public static function buildToolResultWithPicture(?string $base64Data = null, string $text = 'Viewport of the start page'): ToolResult
    {
        $toolResult = new ToolResult($text);
        $toolResult->images = [ToolResultImage::fromMcpImageContentPart(['type' => 'image', 'data' => $base64Data ?? static::buildPngBase64()])];
        return $toolResult;
    }

    protected static function drawPicture(int $width, int $height): \GdImage
    {
        $gdImage = imagecreatetruecolor($width, $height);
        for ($column = 0; $column < $width; $column++) {
            $red = (int)(255 * $column / max(1, $width - 1));
            imageline($gdImage, $column, 0, $column, $height - 1, (int)imagecolorallocate($gdImage, $red, 80, 255 - $red));
        }
        return $gdImage;
    }
}
