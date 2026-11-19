<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Conversations;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffectCatalog;
use DDD\Domain\AI\Services\Conversations\AIConversationAuditRenderService;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * The write ledger of the judge classifies a tool by its declared effect, never by its name: a READ named `preview…`
 * is a read, a WRITE named `get…` is a write; only a name the catalog does not know falls back to the read prefixes.
 */
class AuditWriteLedgerReadsTheDeclaredEffectTest extends TestCase
{
    /** @var mixed The memo of the effect catalog before the test */
    protected mixed $effectsByToolNameBeforeTest = null;

    protected function setUp(): void
    {
        parent::setUp();
        $effectsMemo = new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName');
        $this->effectsByToolNameBeforeTest = $effectsMemo->getValue();
        $effectsMemo->setValue(null, [
            'previewReportKpis' => new MCPToolEffect(MCPToolEffect::READ),
            'getAndArchiveReport' => new MCPToolEffect(MCPToolEffect::WRITE),
        ]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(MCPToolEffectCatalog::class, 'effectsByToolName'))->setValue(null, $this->effectsByToolNameBeforeTest);
        parent::tearDown();
    }

    public function testADeclaredReadIsReadOnlyWhateverItsName(): void
    {
        self::assertTrue(AIConversationAuditRenderService::isReadOnlyToolName('previewReportKpis'));
    }

    public function testADeclaredWriteIsNoReadWhateverItsName(): void
    {
        self::assertFalse(AIConversationAuditRenderService::isReadOnlyToolName('getAndArchiveReport'));
    }

    public function testAnUnknownNameFallsBackToTheReadPrefixes(): void
    {
        self::assertTrue(AIConversationAuditRenderService::isReadOnlyToolName('getSomethingUnbound'));
        self::assertFalse(AIConversationAuditRenderService::isReadOnlyToolName('archiveSomethingUnbound'));
    }
}
