<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Untrusted;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolContentTrust;
use DDD\Infrastructure\Services\IssuesLogService;
use DDD\Tests\Agents\Domain\AI\Services\Conversations\Agent\RecordingIssuesLogServiceDouble;
use DDD\Tests\Agents\Support\IntegrationTestCase;
use Psr\Log\LogLevel;
use ReflectionProperty;

/** The values of `AI.untrustedContent.writeAfterUntrustedRead`: the two opt-in modes, everything else `off`. */
class WriteAfterUntrustedReadModeTest extends IntegrationTestCase
{
    public function testTheOptInModesStayAndAnUnknownValueIsOffWithOneWarning(): void
    {
        $issuesLogServiceDouble = new RecordingIssuesLogServiceDouble();
        $this->replaceService(IssuesLogService::class, $issuesLogServiceDouble);
        (new ReflectionProperty(MCPToolContentTrust::class, 'unknownWriteAfterUntrustedReadModeReported'))->setValue(null, false);

        self::assertSame(MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OWNER_CONSENT, MCPToolContentTrust::normalizeWriteAfterUntrustedReadMode('ownerConsent'));
        self::assertSame(MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_REFUSE, MCPToolContentTrust::normalizeWriteAfterUntrustedReadMode('refuse'));
        self::assertSame(MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF, MCPToolContentTrust::normalizeWriteAfterUntrustedReadMode('off'));
        self::assertSame(MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF, MCPToolContentTrust::normalizeWriteAfterUntrustedReadMode(null));
        self::assertSame([], $issuesLogServiceDouble->loggedLevels);

        self::assertSame(MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF, MCPToolContentTrust::normalizeWriteAfterUntrustedReadMode('onwerConsent'));
        self::assertSame(MCPToolContentTrust::WRITE_AFTER_UNTRUSTED_READ_OFF, MCPToolContentTrust::normalizeWriteAfterUntrustedReadMode(true));
        self::assertSame([LogLevel::WARNING], $issuesLogServiceDouble->loggedLevels, 'once per process');
    }
}
