<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessage;
use DDD\Domain\AI\Entities\Conversations\Messages\AIConversationMessages;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolExecutionTimeout;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolRefreshesState;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolResultIsNeverStubbedByTheBudget;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolReturnsUntrustedContent;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\AI\Dtos\WebSearchHitsMcpResponseDto;
use DDD\Presentation\Mcp\Client\AI\WebPageScreenshotMCPPackage;
use DDD\Presentation\Mcp\Client\AI\WebSearchMCPPackage;
use DDD\Tests\Agents\Support\UnitTestCase;
use ReflectionMethod;

/**
 * The web research tools: the schemas build with the four tool names, every tool reads untrusted content, the
 * research results are never stubbed by the budget, every tool has its time limit, the screenshot refreshes state;
 * the package binding offers them under Web.Search and Web.PageView; the answer instruction dictates the references
 * widget; an earlier search's excerpts are read back for a page.
 */
class WebSearchMCPPackagesSchemaTest extends UnitTestCase
{
    public const array SEARCH_TOOL_NAMES = ['extractInformationFromWebPagesWithFastLLM', 'getWebPageAsMarkdown', 'searchTheWeb'];

    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    public function testTheSchemasBuildWithTheFourTools(): void
    {
        $searchDocumentation = MCPPackageDocumentation::createFromClassName(WebSearchMCPPackage::class);
        $toolNames = [];
        foreach ($searchDocumentation->functions as $toolDocumentation) {
            $toolNames[] = $toolDocumentation->name;
            self::assertNotSame('', trim((string)$toolDocumentation->description), $toolDocumentation->name);
        }
        sort($toolNames);
        self::assertSame(self::SEARCH_TOOL_NAMES, $toolNames);
        self::assertSame('agent.webSearch', $searchDocumentation->name);

        $screenshotDocumentation = MCPPackageDocumentation::createFromClassName(WebPageScreenshotMCPPackage::class);
        self::assertSame('agent.webPageScreenshot', $screenshotDocumentation->name);
        self::assertCount(1, $screenshotDocumentation->functions);

        foreach ([$searchDocumentation, $screenshotDocumentation] as $packageDocumentation) {
            $serializedDocumentation = (string)json_encode($packageDocumentation->toObject(forPersistence: false));
            self::assertStringNotContainsString('array<', $serializedDocumentation);
        }
    }

    public function testEveryToolDeclaresItsAttributes(): void
    {
        foreach (self::SEARCH_TOOL_NAMES as $toolName) {
            $reflectionMethod = new ReflectionMethod(WebSearchMCPPackage::class, $toolName);
            self::assertCount(1, $reflectionMethod->getAttributes(MCPToolReturnsUntrustedContent::class), $toolName);
            self::assertCount(1, $reflectionMethod->getAttributes(MCPToolResultIsNeverStubbedByTheBudget::class), $toolName);
            self::assertSame(45, $reflectionMethod->getAttributes(MCPToolExecutionTimeout::class)[0]->newInstance()->seconds, $toolName);
        }
        $screenshotMethod = new ReflectionMethod(WebPageScreenshotMCPPackage::class, 'getWebPageAsScreenshot');
        self::assertCount(1, $screenshotMethod->getAttributes(MCPToolReturnsUntrustedContent::class));
        self::assertCount(1, $screenshotMethod->getAttributes(MCPToolRefreshesState::class));
    }

    public function testThePackagesBindingOffersTheTools(): void
    {
        $packageBinding = require dirname(__DIR__, 4) . '/config/app/AI/skillPackages.php';

        self::assertSame(['agent.webSearch'], $packageBinding[AISkill::WEB_SEARCH] ?? null);
        self::assertSame(['agent.webPageScreenshot'], $packageBinding[AISkill::WEB_PAGE_VIEW] ?? null);
        self::assertFileExists(dirname(__DIR__, 4) . '/config/app/AI/Skills/Web/Search.md');
        self::assertFileExists(dirname(__DIR__, 4) . '/config/app/AI/Skills/Web/PageView.md');
        self::assertFileExists(dirname(__DIR__, 4) . '/config/app/AI/Prompts/AI/WebSearch/PageExtract.md');
    }

    public function testTheAnswerInstructionDictatesTheReferencesWidget(): void
    {
        $platformNote = WebSearchHitsMcpResponseDto::buildAnswerInstruction(true);

        self::assertStringContainsString('"type":"WebSearchResults"', str_replace(' ', '', $platformNote));
        self::assertStringNotContainsString('WebSearchResults', WebSearchHitsMcpResponseDto::buildAnswerInstruction(false), 'no widget on a phone call');
    }

    public function testTheExcerptsOfAnEarlierSearchAreReadBackForAPage(): void
    {
        $toolCall = new ToolCall();
        $toolCall->name = 'searchTheWeb';
        $toolCall->result = "Pages (1):\n\n[1] Pricing — https://www.example.com/pricing/\nqueries 1\n> Basic from 9 €\n> Pro from 19 €\n\n[2] Other — https://other.example/\n> other";
        $message = new AIConversationMessage();
        $message->toolCall = $toolCall;
        $messages = new AIConversationMessages();
        $messages->add($message);
        $callProvenance = new AgentToolCallProvenance();
        $callProvenance->conversationMessages = $messages;

        $webPageExcerpts = WebSearchMCPPackage::buildExcerptsFromEarlierSearches($callProvenance, ['https://example.com/pricing', 'https://unknown.example/']);

        self::assertSame(["Basic from 9 €\nPro from 19 €"], $webPageExcerpts->findSnippetsOfUrl('https://example.com/pricing'));
        self::assertSame([], $webPageExcerpts->findSnippetsOfUrl('https://unknown.example/'));
        self::assertSame(0, WebSearchMCPPackage::buildExcerptsFromEarlierSearches(new AgentToolCallProvenance(), ['https://example.com'])->count());
    }
}
