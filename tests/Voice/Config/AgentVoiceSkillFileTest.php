<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Config;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Voice\AgentVoiceAccountRights;
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\Voice\AgentVoiceCallsMCPPackage;
use DDD\Tests\Agents\Voice\Support\AgentVoiceIntegrationTestCase;

/**
 * The skill `Agent.Voice` the package ships: its file lies where the skill name points, the package's loader and
 * skill-file lint read it without a problem, it requires the same right as the tool package, names the three tools
 * (and no tool the package does not have), names the agent only through the persona placeholder, and words nothing of
 * a phone line or a product. While the voice channel is off, a skill bound to the package alone is not offered.
 */
class AgentVoiceSkillFileTest extends AgentVoiceIntegrationTestCase
{
    /** @var string[] The tool names of the package, in the order the skill lists its work */
    public const array TOOL_NAMES = ['startVoiceCall', 'endVoiceCall', 'getVoiceCalls'];

    /** @var string The words of a phone line, an SMS or a phone number: the package has none of them */
    public const string PHONE_WORDS_PATTERN = '/\b(phone|phones|telephone|sms|text message|dial|dials|dialled|voicemail|landline|mobile number|phone number|pin)\b/i';

    public static function getSkillsDirectory(): string
    {
        return dirname(__DIR__, 3) . '/config/app/AI/Skills';
    }

    public static function getSkillFile(): string
    {
        return static::getSkillsDirectory() . '/' . str_replace('.', '/', AISkill::AGENT_VOICE) . '.md';
    }

    public function testTheSkillFileLiesWhereTheSkillNamePoints(): void
    {
        static::assertSame('Agent.Voice', AISkill::AGENT_VOICE);
        static::assertFileExists(static::getSkillFile());
    }

    public function testTheFrontmatterParsesTheWayTheAgentPackageParsesSkillFiles(): void
    {
        $skillFrontmatterAndBody = AISkillsService::splitFrontmatter((string)file_get_contents(static::getSkillFile()));

        static::assertSame(['requiredAccountRights', 'decisionCriteria', 'shortDescription', 'description'], array_keys($skillFrontmatterAndBody->frontmatter));
        static::assertSame([], array_diff(array_keys($skillFrontmatterAndBody->frontmatter), AISkillsService::SKILL_FRONTMATTER_KEYS));
        static::assertSame([AgentVoiceAccountRights::USE_VOICE], $skillFrontmatterAndBody->frontmatter['requiredAccountRights']);
        static::assertStringStartsWith('# Voice calls with the owner', trim($skillFrontmatterAndBody->body));
    }

    public function testTheSkillRequiresTheRightOfItsToolPackage(): void
    {
        $skillFrontmatterAndBody = AISkillsService::splitFrontmatter((string)file_get_contents(static::getSkillFile()));

        static::assertSame(MCPPackage::findRequiredAccountRightNamesOfClass(AgentVoiceCallsMCPPackage::class), $skillFrontmatterAndBody->frontmatter['requiredAccountRights']);
    }

    public function testTheSkillFileLintOfTheAgentPackageFindsNoProblem(): void
    {
        /** @var AISkillFilesLintService $aISkillFilesLintService */
        $aISkillFilesLintService = DDDService::instance()->getService(AISkillFilesLintService::class);

        static::assertSame([], $aISkillFilesLintService->findSkillFileProblems([static::getSkillsDirectory()]));
        static::assertSame([], $aISkillFilesLintService->findSkillNamingProblems([static::getSkillsDirectory()]));
    }

    public function testTheLoaderReadsTheSkillWithThePersonasAgentName(): void
    {
        $this->declareAgentPersonaConfiguration(['agentName' => 'Helper']);
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);

        $aISkill = $aISkillsService->getAISkillByName(AISkill::AGENT_VOICE);

        static::assertInstanceOf(AISkill::class, $aISkill);
        static::assertSame([AgentVoiceAccountRights::USE_VOICE], $aISkill->requiredAccountRights);
        static::assertStringStartsWith('Voice calls between Helper and the owner: call the owner now', $aISkill->description);
        static::assertSame('calls with Helper: it calls you in the app now, running calls and hanging up, the call log', $aISkill->shortDescription);
        static::assertSame('Voice calls between Helper and the owner: a call now, the calls ringing or running and hanging up, past calls.', $aISkill->decisionCriteria);
        static::assertStringNotContainsString('{%', $aISkill->description . $aISkill->shortDescription . $aISkill->decisionCriteria);
    }

    public function testTheSkillNamesTheThreeToolsAndNoToolThePackageDoesNotHave(): void
    {
        $skillText = (string)file_get_contents(static::getSkillFile());
        $packageArgumentNames = [];
        $packageToolNames = [];
        foreach (MCPPackageDocumentation::createFromClassName(AgentVoiceCallsMCPPackage::class)->functions as $toolDocumentation) {
            $packageToolNames[] = $toolDocumentation->name;
            $inputSchema = json_decode((string)json_encode($toolDocumentation->inputSchema->toObject(forPersistence: false)), true);
            array_push($packageArgumentNames, ...array_keys($inputSchema['properties'] ?? []));
        }
        sort($packageToolNames);
        $expectedToolNames = static::TOOL_NAMES;
        sort($expectedToolNames);
        static::assertSame($expectedToolNames, $packageToolNames);

        foreach (static::TOOL_NAMES as $toolName) {
            static::assertStringContainsString('`' . $toolName . '`', $skillText, $toolName);
        }
        preg_match_all('/`([a-z][a-z0-9]*[A-Z][A-Za-z0-9]*)/', $skillText, $backtickedIdentifierMatches);
        $unknownIdentifiers = array_values(array_diff(array_unique($backtickedIdentifierMatches[1]), static::TOOL_NAMES, $packageArgumentNames));
        static::assertSame([], $unknownIdentifiers, 'every tool or argument the skill names is one of the package');
    }

    public function testTheSkillNamesTheAgentOnlyThroughThePersonaAndWordsNoPhoneLine(): void
    {
        $skillText = (string)file_get_contents(static::getSkillFile());

        preg_match_all('/\{%[A-Z_]+%\}/', $skillText, $placeholderMatches);
        static::assertSame([AISkillsService::PLACEHOLDER_AGENT_NAME], array_values(array_unique($placeholderMatches[0])));
        static::assertStringNotContainsString(AISkillsService::PLACEHOLDER_PRODUCT_NAME, $skillText);
        static::assertSame(0, preg_match(static::PHONE_WORDS_PATTERN, $skillText, $phoneWordMatch), 'phone word: ' . ($phoneWordMatch[0] ?? ''));
        static::assertSame(0, preg_match('/\+?\d[\d \-]{6,}\d/', $skillText), 'no phone number');
    }

    public function testEverySkillTheFileRefersToIsASkillTheLoaderKnows(): void
    {
        preg_match_all('/\b(Agent\.[A-Z][A-Za-z]+)\b/', (string)file_get_contents(static::getSkillFile()), $skillNameMatches);
        $referredSkillNames = array_values(array_unique($skillNameMatches[1]));
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);

        static::assertSame([AISkill::AGENT_TASKS], $referredSkillNames, 'timed calls are referred to the scheduled tasks');
        foreach ($referredSkillNames as $referredSkillName) {
            static::assertInstanceOf(AISkill::class, $aISkillsService->getAISkillByName($referredSkillName), $referredSkillName);
        }
    }

    public function testASkillBoundToThePackageAloneIsNotOfferedWhileTheVoiceChannelIsOff(): void
    {
        $packageBinding = [AISkill::AGENT_VOICE => [AgentVoiceCallsMCPPackage::class]];

        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => false]));
        static::assertSame([], AISkill::withoutUnavailablePackages($packageBinding));
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith(['enabled' => true]));
        static::assertSame($packageBinding, AISkill::withoutUnavailablePackages($packageBinding));
    }
}
