<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Documents;

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

/** Documents of the extraction tests, built in the test: a PDF, Word, Excel, PowerPoint, OpenDocument, a plain ZIP. */
class AgentDocumentFixtures
{
    /** A PDF with one page per text (Dompdf, page breaks between them). */
    public static function buildPdf(string ...$pageTexts): string
    {
        $temporaryDirectory = static::createTemporaryDirectory();
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);
        $options->set('fontDir', $temporaryDirectory);
        $options->set('fontCache', $temporaryDirectory);
        $options->set('tempDir', $temporaryDirectory);
        $options->set('chroot', $temporaryDirectory);
        $dompdf = new Dompdf($options);
        $pageParts = [];
        foreach ($pageTexts as $pageText) {
            $pageParts[] = '<p>' . htmlspecialchars($pageText) . '</p>';
        }
        $dompdf->loadHtml('<html><body>' . implode('<div style="page-break-after: always"></div>', $pageParts) . '</body></html>');
        $dompdf->render();
        $pdfBytes = (string)$dompdf->output();
        exec('rm -rf ' . escapeshellarg($temporaryDirectory));
        return $pdfBytes;
    }

    /** @param string[] $paragraphTexts */
    public static function buildDocx(array $paragraphTexts): string
    {
        $paragraphsXml = '';
        foreach ($paragraphTexts as $paragraphText) {
            $paragraphsXml .= '<w:p><w:r><w:t xml:space="preserve">' . htmlspecialchars($paragraphText, ENT_XML1) . '</w:t></w:r></w:p>';
        }
        return static::buildZip([
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
                . $paragraphsXml . '<w:p><w:r><w:t>Tab</w:t><w:tab/><w:t>after</w:t></w:r></w:p></w:body></w:document>',
            'word/vbaProject.bin' => 'MACRO-BYTES-NEVER-READ',
        ]);
    }

    /** @param string[] $slideTexts one text per slide, in order */
    public static function buildPptx(array $slideTexts): string
    {
        $slideIds = '';
        $relationships = '';
        $zipEntries = ['[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>'];
        // the slides are named in reverse so the order must come from the presentation, not the entry names
        foreach (array_values($slideTexts) as $slideIndex => $slideText) {
            $slideNumber = count($slideTexts) - $slideIndex;
            $slideIds .= '<p:sldId id="' . (256 + $slideIndex) . '" r:id="rId' . ($slideIndex + 1) . '"/>';
            $relationships .= '<Relationship Id="rId' . ($slideIndex + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $slideNumber . '.xml"/>';
            $zipEntries['ppt/slides/slide' . $slideNumber . '.xml'] = '<?xml version="1.0" encoding="UTF-8"?><p:sld xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><p:cSld><p:spTree><p:sp><p:txBody><a:p><a:r><a:t>'
                . htmlspecialchars($slideText, ENT_XML1) . '</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>';
        }
        $zipEntries['ppt/presentation.xml'] = '<?xml version="1.0" encoding="UTF-8"?><p:presentation xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><p:sldIdLst>' . $slideIds . '</p:sldIdLst></p:presentation>';
        $zipEntries['ppt/_rels/presentation.xml.rels'] = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relationships . '</Relationships>';
        return static::buildZip($zipEntries);
    }

    /** An Excel workbook with the sheets "Plan" and "Costs" (PhpSpreadsheet). */
    public static function buildXlsx(): string
    {
        $spreadsheet = new Spreadsheet();
        $planSheet = $spreadsheet->getActiveSheet();
        $planSheet->setTitle('Plan');
        $planSheet->setCellValue('A1', 'Task');
        $planSheet->setCellValue('B1', 'Owner');
        $planSheet->setCellValue('A2', 'Launch');
        $planSheet->setCellValue('C2', 42);
        $costsSheet = $spreadsheet->createSheet();
        $costsSheet->setTitle('Costs');
        $costsSheet->setCellValue('A1', 'Rent');
        $costsSheet->setCellValue('B1', 1200);
        $temporaryPath = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($temporaryPath);
        $xlsxBytes = (string)file_get_contents($temporaryPath);
        unlink($temporaryPath);
        return $xlsxBytes;
    }

    /** @param string[] $paragraphTexts */
    public static function buildOdt(array $paragraphTexts): string
    {
        $paragraphsXml = '';
        foreach ($paragraphTexts as $paragraphText) {
            $paragraphsXml .= '<text:p>' . htmlspecialchars($paragraphText, ENT_XML1) . '</text:p>';
        }
        return static::buildZip([
            'mimetype' => 'application/vnd.oasis.opendocument.text',
            'content.xml' => '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"><office:body><office:text><text:h>Heading</text:h>'
                . $paragraphsXml . '</office:text></office:body></office:document-content>',
        ]);
    }

    /** An OpenDocument spreadsheet with one sheet "Stock": a header row and a row repeated far beyond the cap. */
    public static function buildOds(): string
    {
        return static::buildZip([
            'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
            'content.xml' => '<?xml version="1.0" encoding="UTF-8"?><office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0"><office:body><office:spreadsheet>'
                . '<table:table table:name="Stock"><table:table-row><table:table-cell><text:p>Item</text:p></table:table-cell><table:table-cell table:number-columns-repeated="3"><text:p>x</text:p></table:table-cell></table:table-row>'
                . '<table:table-row table:number-rows-repeated="1000000"><table:table-cell><text:p>same</text:p></table:table-cell></table:table-row>'
                . '<table:table-row table:number-rows-repeated="1048000"><table:table-cell/></table:table-row></table:table>'
                . '</office:spreadsheet></office:body></office:document-content>',
        ]);
    }

    public static function buildPlainZip(): string
    {
        return static::buildZip(['notes.txt' => 'hello']);
    }

    /** The first bytes of a legacy Office (OLE compound) file. */
    public static function buildLegacyOfficeBytes(): string
    {
        return "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 504);
    }

    /** @param array<string, string> $entriesByName */
    public static function buildZip(array $entriesByName): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'zip');
        $zipArchive = new ZipArchive();
        $zipArchive->open($temporaryPath, ZipArchive::OVERWRITE);
        foreach ($entriesByName as $entryName => $entryBytes) {
            $zipArchive->addFromString($entryName, $entryBytes);
        }
        $zipArchive->close();
        $zipBytes = (string)file_get_contents($temporaryPath);
        unlink($temporaryPath);
        return $zipBytes;
    }

    protected static function createTemporaryDirectory(): string
    {
        $temporaryDirectory = sys_get_temp_dir() . '/agent-document-fixture-' . bin2hex(random_bytes(6));
        mkdir($temporaryDirectory, 0777, true);
        return $temporaryDirectory;
    }
}
