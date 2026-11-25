<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Documents\AgentDocumentTextExtractionService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use DDD\Presentation\Mcp\Client\Agents\AgentDriveMCPPackage;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Installation check `documentTools`: the drive's document tools ({@see AgentDriveMCPPackage}) are bound and the
 * document reading is on (`AI.documents.enabled`), but a program they start is not on the PATH: poppler's `pdftotext`
 * and `pdfinfo` (every PDF), `pdftoppm` (page images, OCR) and `tesseract` (OCR, when `ocrEnabled`). A PDF read then
 * fails at runtime. WARNING per missing program.
 */
class AgentDocumentToolsInstallationCheckService extends Service implements AgentInstallationCheckInterface
{
    public const string CHECK_NAME = 'documentTools';

    /** @var string[] The poppler programs every PDF read starts */
    public const array PDF_TEXT_PROGRAMS = ['pdftotext', 'pdfinfo'];

    /** @var string The poppler program that renders a page (page images and OCR) */
    public const string PDF_PAGE_RENDER_PROGRAM = 'pdftoppm';

    /** @var string The OCR program */
    public const string OCR_PROGRAM = 'tesseract';

    public function getInstallationCheckName(): string
    {
        return static::CHECK_NAME;
    }

    public function findInstallationFindings(): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        if (!$this->areDocumentToolsBound()) {
            return $agentInstallationFindings;
        }
        /** @var AgentDocumentTextExtractionService $agentDocumentTextExtractionService */
        $agentDocumentTextExtractionService = DDDService::instance()->getService(AgentDocumentTextExtractionService::class);
        $documentSettings = $agentDocumentTextExtractionService->getSettings();
        if (!$documentSettings->enabled) {
            return $agentInstallationFindings;
        }
        $requiredPrograms = static::PDF_TEXT_PROGRAMS;
        if ($documentSettings->pageImagesEnabled || $documentSettings->ocrEnabled) {
            $requiredPrograms[] = static::PDF_PAGE_RENDER_PROGRAM;
        }
        if ($documentSettings->ocrEnabled) {
            $requiredPrograms[] = static::OCR_PROGRAM;
        }
        foreach ($requiredPrograms as $requiredProgram) {
            if (!$this->isProgramOnPath($requiredProgram)) {
                $agentInstallationFindings->addFinding(
                    static::CHECK_NAME,
                    AgentInstallationFinding::SEVERITY_WARNING,
                    $requiredProgram,
                    "The document tools are bound, but '$requiredProgram' is not on the PATH: reading a PDF fails.",
                    $requiredProgram === static::OCR_PROGRAM
                        ? 'Install tesseract in the image of the workers, or set ocrEnabled to false in config/app/AI/documents.php.'
                        : 'Install poppler-utils in the image of the workers, or set enabled to false in config/app/AI/documents.php.'
                );
            }
        }
        return $agentInstallationFindings;
    }

    /** Whether a skill of the effective binding binds the drive's document tools (the class or a subclass of it) */
    protected function areDocumentToolsBound(): bool
    {
        foreach (AISkill::getPackageClassesBySkillName() as $packageClasses) {
            foreach ($packageClasses as $packageClass) {
                if (is_a($packageClass, AgentDriveMCPPackage::class, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Whether `$programName` is an executable on the PATH (overridable for a test) */
    protected function isProgramOnPath(string $programName): bool
    {
        return (new ExecutableFinder())->find($programName) !== null;
    }
}
