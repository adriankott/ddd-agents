<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Testing\Surface;

use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlay;
use DDD\Testing\Agents\Surface\AgentSurfaceDocumentBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * What the surface document holds of a tool beside its documentation: the attributes of its method as declared, and
 * what the application's overlay sets for it. Both are read without a container.
 */
class AgentSurfaceDocumentBuilderDeclarationsTest extends TestCase
{
    public function testEveryAttributeOfAToolMethodButTheToolAttributeIsADeclarationByItsShortName(): void
    {
        $declarations = (new AgentSurfaceDocumentBuilder())->buildDeclarationsOfToolMethod(
            new ReflectionMethod(AgentSurfaceDeclarationsToolsFixture::class, 'sendWeeklyReport')
        );

        self::assertSame(
            ['MCPToolEffect', 'MCPToolOwnerFacingSubject', 'MCPToolShowsNoActivityLine', 'MCPToolSummary'],
            array_keys($declarations)
        );
        self::assertSame(['WRITE', 'isOutwardAction' => true], $declarations['MCPToolEffect']);
        self::assertSame(['The weekly report'], $declarations['MCPToolOwnerFacingSubject']);
    }

    public function testAnAttributeWithoutArgumentsIsTrueAndALongArgumentIsOneToken(): void
    {
        $declarations = (new AgentSurfaceDocumentBuilder())->buildDeclarationsOfToolMethod(
            new ReflectionMethod(AgentSurfaceDeclarationsToolsFixture::class, 'sendWeeklyReport')
        );

        self::assertTrue($declarations['MCPToolShowsNoActivityLine']);
        self::assertMatchesRegularExpression('/^#[0-9a-f]{12} \(\d+ characters\)$/', $declarations['MCPToolSummary']);
    }

    public function testAToolThatDeclaresNothingBesideTheToolAttributeHasNoDeclarations(): void
    {
        $declarations = (new AgentSurfaceDocumentBuilder())->buildDeclarationsOfToolMethod(
            new ReflectionMethod(AgentSurfaceDeclarationsToolsFixture::class, 'toolWithoutDeclarations')
        );

        self::assertSame([], $declarations);
    }

    public function testAnOverlayIsTheKeysItSetsAndItsOwnerFacingSubject(): void
    {
        $toolOverlay = MCPToolOverlay::fromConfigEntry('sendWeeklyReport', [
            MCPToolOverlay::KEY_DESCRIPTION => 'Sends the report of the week.',
            MCPToolOverlay::KEY_ACCOUNT_RIGHTS => ['manageReports'],
            MCPToolOverlay::KEY_PARAMETERS => ['agentSpaceId' => false, 'week' => 'The ISO week.'],
            MCPToolOverlay::KEY_OWNER_FACING_SUBJECT => 'Weekly report',
        ]);

        self::assertSame(
            [
                'sets' => ['accountRights', 'description', 'ownerFacingSubject', 'parameters'],
                'ownerFacingSubject' => 'Weekly report',
            ],
            (new AgentSurfaceDocumentBuilder())->buildOverlayEntry($toolOverlay)
        );
    }

    public function testAnOverlayThatSetsNothingIsLeftOut(): void
    {
        self::assertSame([], (new AgentSurfaceDocumentBuilder())->buildOverlayEntry(MCPToolOverlay::fromConfigEntry('getWeeklyReport', [])));
    }
}
