<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Drives;

use DDD\Domain\AI\Entities\Drives\AgentFileSourceSettings;
use DDD\Domain\AI\Entities\MCPPackages\ToolCallImageReference;
use DDD\Domain\AI\Entities\MCPPackages\ToolResultImage;
use DDD\Domain\AI\Services\Conversations\Agent\AgentToolCallImageStoreService;
use DDD\Domain\Common\Entities\Files\Sources\FileContent;
use DDD\Domain\Common\Entities\Files\Sources\FileFacts;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowDecision;
use DDD\Domain\Common\Entities\Files\Sources\FileFlowTarget;
use DDD\Domain\Common\Entities\Files\Sources\FileReference;
use DDD\Domain\Common\Services\Files\FileFlowPolicyService;
use DDD\Domain\Common\Services\Files\FileSourcesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;

/**
 * Pictures of files for the model: the generic tool picture store `files`. A picture of any file source
 * ({@see \DDD\Domain\Common\Services\Files\FileSource}) is rendered by the server (pinned decoder, upright, without
 * metadata, scaled), brought to the wire profile and kept by {@see AgentToolCallImageStoreService} in its tiers; the
 * tool call persists only the reference `<source>/<id>:<edge>:<fingerprint>` (never bytes).
 *
 * Every read — the first and each later model call — checks the caller's rights on the source and the application's
 * flow rule first, then reads the tiers, and renders from the source again on a miss. The fingerprint (the start of
 * the sha256 of the source bytes) pins the picture: a file whose bytes changed since is no longer this picture. A
 * picture the flow rule forbids to keep durably (a person's own file) lives in the caches only, never in the object
 * store.
 */
class AgentFilePicturesService extends Service
{
    /** @var string The store name of the pictures this service serves */
    public const string STORE_NAME = 'files';

    /** @var int The length of the fingerprint of the source bytes in a picture id */
    public const int FINGERPRINT_LENGTH = 12;

    /** @var string `<source>/<id>:<edge>` with an optional `:<fingerprint>` */
    public const string PICTURE_ID_PATTERN = '#^(' . FileReference::SOURCE_NAME_PATTERN . '/' . FileReference::ID_PATTERN . '):([1-9][0-9]{1,4})(?::([0-9a-f]{12}))?$#';

    /**
     * Renders the picture of a file for the model and keeps it in the tiers.
     *
     * @throws NotFoundException When there is no such file for the caller
     * @throws ForbiddenException When the flow rule keeps the file from the model
     * @throws BadRequestException When the file is no raster picture, or over the limits
     */
    public function renderPictureForModel(FileReference $fileReference, int $maxEdgePixels): ToolResultImage
    {
        $fileFacts = $this->getFileSourcesService()->requireFileFacts($fileReference);
        $fileFlowDecision = $this->requireFlowToModel($fileFacts);
        if (!$fileFacts->isImage()) {
            throw new BadRequestException(sprintf(
                '%s is no picture (%s). Read a document with readDriveDocument.',
                $fileReference->toString(),
                $fileFacts->contentType ?? strtolower($fileFacts->family)
            ));
        }
        $settings = $this->getSettings();
        $fileContent = $this->getFileSourcesService()->readFileContent($fileReference, $settings->maxViewedImageSourceBytes);
        return $this->renderAndKeepPicture($fileContent, $maxEdgePixels, $fileFlowDecision, $settings);
    }

    /**
     * The picture behind a persisted reference of the store `files`, or null when the file is gone, is no longer
     * readable for the caller, must not go to the model any more, or holds other bytes than when it was shown.
     */
    public function resolvePicture(ToolCallImageReference $toolCallImageReference): ?ToolResultImage
    {
        if ($toolCallImageReference->sourceStore !== self::STORE_NAME
            || preg_match(self::PICTURE_ID_PATTERN, $toolCallImageReference->sourceImageId, $pictureIdMatches) !== 1
        ) {
            return null;
        }
        $fileReference = $this->getFileSourcesService()->findFileReference($pictureIdMatches[1]);
        $maxEdgePixels = (int)$pictureIdMatches[2];
        $fingerprint = $pictureIdMatches[3] ?? null;
        if ($fileReference === null) {
            return null;
        }
        try {
            // rights and the flow rule first: the tiers are keyed by the bytes and know no caller
            $fileFacts = $this->getFileSourcesService()->requireFileFacts($fileReference);
            $fileFlowDecision = $this->requireFlowToModel($fileFacts);
            if ($fingerprint !== null && $fileFacts->sha256 !== null && !str_starts_with($fileFacts->sha256, $fingerprint)) {
                return null;
            }
            $keptPicture = $this->getAgentToolCallImageStoreService()->readSourcePicture($toolCallImageReference, $fileFlowDecision->isDurableCopyAllowed);
            if ($keptPicture !== null) {
                return $keptPicture;
            }
            $settings = $this->getSettings();
            $fileContent = $this->getFileSourcesService()->readFileContent($fileReference, $settings->maxViewedImageSourceBytes);
            if ($fingerprint !== null && $this->buildFingerprint($fileContent) !== $fingerprint) {
                return null;
            }
            return $this->renderAndKeepPicture($fileContent, $maxEdgePixels, $fileFlowDecision, $settings);
        } catch (NotFoundException|ForbiddenException|BadRequestException) {
            return null;
        }
    }

    /** The id of a file's picture in the store: `<source>/<id>:<edge>:<fingerprint of the source bytes>` */
    public function buildPictureId(FileReference $fileReference, int $maxEdgePixels, string $fingerprint): string
    {
        return $fileReference->toString() . ':' . $maxEdgePixels . ':' . $fingerprint;
    }

    public function getSettings(): AgentFileSourceSettings
    {
        return AgentFileSourceSettings::fromConfigNode(Config::get(AgentFileSourceSettings::CONFIG_KEY));
    }

    /**
     * @throws ForbiddenException When the flow rule keeps the file from the model
     */
    protected function requireFlowToModel(FileFacts $fileFacts): FileFlowDecision
    {
        $fileFlowDecision = $this->getFileFlowPolicyService()->decideFileFlow($fileFacts, FileFlowTarget::forModel());
        if (!$fileFlowDecision->isAllowed) {
            throw new ForbiddenException($fileFlowDecision->refusalReason ?? sprintf('%s must not be shown here.', $fileFacts->fileReference->toString()));
        }
        return $fileFlowDecision;
    }

    /**
     * @throws BadRequestException When the bytes are no raster picture the model can be shown
     */
    protected function renderAndKeepPicture(
        FileContent $fileContent,
        int $maxEdgePixels,
        FileFlowDecision $fileFlowDecision,
        AgentFileSourceSettings $settings
    ): ToolResultImage {
        $fileReference = $fileContent->fileFacts->fileReference;
        $fileImagePreview = $this->getFileSourcesService()->renderImagePreviewOfContent($fileContent, $maxEdgePixels, $settings->maxViewedImageSourcePixels);
        $toolResultImage = ToolResultImage::fromMcpImageContentPart([
            'type' => 'image',
            'data' => base64_encode($fileImagePreview->getContent()),
            'mimeType' => $fileImagePreview->contentType,
            'sourceStore' => self::STORE_NAME,
            'sourceImageId' => $this->buildPictureId($fileReference, $maxEdgePixels, $this->buildFingerprint($fileContent)),
        ]);
        if ($toolResultImage === null) {
            throw new BadRequestException(sprintf('%s could not be prepared as a picture for the model.', $fileReference->toString()));
        }
        $this->getAgentToolCallImageStoreService()->writeSourcePicture($toolResultImage, $fileFlowDecision->isDurableCopyAllowed);
        return $toolResultImage;
    }

    protected function buildFingerprint(FileContent $fileContent): string
    {
        return substr(hash('sha256', $fileContent->getContent()), 0, self::FINGERPRINT_LENGTH);
    }

    protected function getFileSourcesService(): FileSourcesService
    {
        /** @var FileSourcesService $fileSourcesService */
        $fileSourcesService = DDDService::instance()->getService(FileSourcesService::class);
        return $fileSourcesService;
    }

    protected function getFileFlowPolicyService(): FileFlowPolicyService
    {
        /** @var FileFlowPolicyService $fileFlowPolicyService */
        $fileFlowPolicyService = DDDService::instance()->getService(FileFlowPolicyService::class);
        return $fileFlowPolicyService;
    }

    protected function getAgentToolCallImageStoreService(): AgentToolCallImageStoreService
    {
        /** @var AgentToolCallImageStoreService $agentToolCallImageStoreService */
        $agentToolCallImageStoreService = DDDService::instance()->getService(AgentToolCallImageStoreService::class);
        return $agentToolCallImageStoreService;
    }
}
