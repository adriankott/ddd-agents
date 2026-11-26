<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Drives;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The settings `AI.fileSources` (`config/app/AI/fileSources.php`): how the agent's drive tools treat files of the
 * application's file sources.
 */
class AgentFileSourceSettings extends ValueObject
{
    public const string CONFIG_KEY = 'AI.fileSources';

    /** @var int The most pictures one viewImages call shows, whatever the configuration says */
    public const int MAX_VIEWED_IMAGES_CEILING = 4;

    /** @var array<string, string> The tool that finds or lists the files of a source, by source name */
    public array $findToolsBySourceName = [];

    /** @var int The most pictures one viewImages call shows */
    public int $maxViewedImagesPerCall = self::MAX_VIEWED_IMAGES_CEILING;

    /** @var int The largest source picture viewImages renders */
    public int $maxViewedImageSourceBytes = 20_971_520;

    /** @var int The most pixels of a source picture viewImages renders */
    public int $maxViewedImageSourcePixels = 50_000_000;

    public static function fromConfigNode(mixed $configNode): AgentFileSourceSettings
    {
        $settings = new static();
        if (!is_array($configNode)) {
            return $settings;
        }
        foreach (is_array($configNode['findTools'] ?? null) ? $configNode['findTools'] : [] as $sourceName => $toolName) {
            if (is_string($sourceName) && is_string($toolName) && $toolName !== '') {
                $settings->findToolsBySourceName[$sourceName] = $toolName;
            }
        }
        $viewImagesNode = is_array($configNode['viewImages'] ?? null) ? $configNode['viewImages'] : [];
        if (is_int($viewImagesNode['maxPerCall'] ?? null)) {
            $settings->maxViewedImagesPerCall = max(1, min(static::MAX_VIEWED_IMAGES_CEILING, $viewImagesNode['maxPerCall']));
        }
        if (is_int($viewImagesNode['maxSourceBytes'] ?? null) && $viewImagesNode['maxSourceBytes'] > 0) {
            $settings->maxViewedImageSourceBytes = $viewImagesNode['maxSourceBytes'];
        }
        if (is_int($viewImagesNode['maxSourcePixels'] ?? null) && $viewImagesNode['maxSourcePixels'] > 0) {
            $settings->maxViewedImageSourcePixels = $viewImagesNode['maxSourcePixels'];
        }
        return $settings;
    }
}
