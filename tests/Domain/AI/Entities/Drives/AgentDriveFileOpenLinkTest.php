<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Drives;

use DDD\Domain\AI\Entities\Drives\AgentDriveFile;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The open link of a file card: a path of the host or an http(s) URL only.
 */
class AgentDriveFileOpenLinkTest extends UnitTestCase
{
    public function testHostPathsAndHttpUrlsAreKept(): void
    {
        foreach (['/admin/drive?space=12&path=%2FTemplates%2Fletter.typ&file=812', 'https://admin.example.org/drive?file=1', 'http://localhost:3000/drive'] as $openLink) {
            $agentDriveFile = new AgentDriveFile();
            $agentDriveFile->setOpenLinkIfSafe($openLink);
            self::assertSame($openLink, $agentDriveFile->openLink, $openLink);
        }
    }

    public function testScriptDataProtocolRelativeAndBrokenLinksAreDropped(): void
    {
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', '//evil.example.net/x', '/\\evil.example.net', "/admin\n/x", 'admin/drive', '', 'ftp://x'] as $openLink) {
            $agentDriveFile = new AgentDriveFile();
            $agentDriveFile->setOpenLinkIfSafe($openLink);
            self::assertNull($agentDriveFile->openLink, $openLink);
        }
        $agentDriveFile = new AgentDriveFile();
        $agentDriveFile->setOpenLinkIfSafe(null);
        self::assertNull($agentDriveFile->openLink);
    }
}
