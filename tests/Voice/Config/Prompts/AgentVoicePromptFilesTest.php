<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Config\Prompts;

use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoiceAIPromptNames;
use DDD\Domain\AI\Entities\Voice\Prompts\AgentVoicePromptText;
use DDD\Domain\AI\Services\Voice\AgentVoiceCallAdmissionService;
use DDD\Tests\Agents\Voice\Support\AgentVoiceUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

/**
 * The prompt files of the voice channel (`config/app/AI/Prompts/AI/Voice/*.md`, `AI/VoiceCalls.md`): every name
 * constant of {@see AgentVoiceAIPromptNames} has its file and is found by the prompt service, every file has its
 * constant, no file names a phone line, a phone network or a location, and every placeholder a file uses is one its reader
 * fills (`AGENT_NAME` in every text, from the persona). The agent's name reaches a text only through the persona.
 */
class AgentVoicePromptFilesTest extends AgentVoiceUnitTestCase
{
    /**
     * The placeholders each reader fills besides `AGENT_NAME` (read from the callers of {@see AgentVoicePromptText::forName()}).
     *
     * @var array<string, string[]>
     */
    protected const array PLACEHOLDERS_FILLED_BY_THE_READER = [
        AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING => ['ACCOUNT_ID'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING_AGENT_STARTED_CALL => ['ACCOUNT_ID'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING_WITHOUT_CONVERSATION => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_CALL_TASK => ['CALL_TASK'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_SPEECH_DELIVERY => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_GROUP_WORDS => ['GERMAN_EXAMPLES'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_GROUP_WORDS_GERMAN_EXAMPLES => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_FIXED => ['LANGUAGE_NAME'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_MIRRORED => ['LANGUAGE_NAME'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_OF_THE_CALLER => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_MID_CALL => ['LANGUAGE_NAME'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_MID_CALL_ENGLISH => ['LANGUAGE_NAME'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_LANGUAGE_KEPT => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_SPEAKING_STYLE => ['SPEAKING_STYLE'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_DELEGATION_RULES => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_OPENING_SEED => ['LANGUAGE_INSTRUCTION'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_OPENING_SEED_AGENT_STARTED_CALL => ['LANGUAGE_INSTRUCTION'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_CHARACTER => ['CHARACTER'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_STANDING_RULES => ['STANDING_RULES'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_LAST_DISCUSSED => ['LAST_DISCUSSED'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_SEED_PRODUCT_SCOPE => ['PRODUCT_SCOPE'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_SILENCE_CHECK => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_QUESTION_REPEAT => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_GOODBYE => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_GOODBYE_AFTER_SILENCE_CHECKS => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_BUDGET_END => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_REFUSED_CALL_GOODBYE => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_ANSWER_SHOWN_IN_THE_CHAT => ['LEAD_IN'],
        AgentVoiceAIPromptNames::APP_AI_VOICE_ANSWER_CUT => [],
        AgentVoiceAIPromptNames::APP_AI_VOICE_STEP_WITHOUT_LINE => ['STEP_WORDS'],
    ];

    /** @var string Words of an application that no shipped prompt may use (case-insensitive, whole words) */
    protected const string FORBIDDEN_WORDS_PATTERN = '/\b(phone|phones|telephone|sms|location|locations|twilio|sip|dial|dialed|hotline)\b/i';

    protected function setUp(): void
    {
        parent::setUp();
        $this->declareAgentVoiceConfiguration(static::buildShippedAgentVoiceConfigurationWith([]));
        $this->declareAgentPersonaConfiguration(['agentName' => 'Nova', 'productName' => 'the workbench']);
    }

    protected static function getPromptsDirectory(): string
    {
        return dirname(__DIR__, 4) . '/config/app/AI/Prompts';
    }

    protected static function getPromptFilePath(string $promptName): string
    {
        return static::getPromptsDirectory() . '/' . str_replace('.', '/', $promptName) . '.md';
    }

    /** @return array<string, string> constant name => prompt name */
    protected static function readPromptNameConstants(): array
    {
        return (new ReflectionClass(AgentVoiceAIPromptNames::class))->getConstants();
    }

    /** @return array<string, array{string}> */
    public static function providePromptNames(): array
    {
        $promptNames = [];
        foreach (static::readPromptNameConstants() as $constantName => $promptName) {
            $promptNames[$constantName] = [$promptName];
        }

        return $promptNames;
    }

    #[DataProvider('providePromptNames')]
    public function testEveryPromptNameHasItsFileAndIsFound(string $promptName): void
    {
        static::assertFileExists(static::getPromptFilePath($promptName));
        static::assertNotSame('', AgentVoicePromptText::forName($promptName), 'the prompt service finds ' . $promptName);
    }

    public function testEveryFileHasItsPromptName(): void
    {
        $promptNamesOfTheFiles = [];
        foreach ((array)glob(static::getPromptsDirectory() . '/AI/Voice/*.md') as $promptFilePath) {
            $promptNamesOfTheFiles[] = 'AI.Voice.' . basename((string)$promptFilePath, '.md');
        }
        $promptNames = array_values(static::readPromptNameConstants());
        sort($promptNamesOfTheFiles);
        sort($promptNames);

        static::assertSame($promptNames, $promptNamesOfTheFiles);
        static::assertCount(30, $promptNames);
    }

    public function testEveryReaderIsKnownForEveryPromptName(): void
    {
        $promptNames = array_values(static::readPromptNameConstants());
        $promptNamesOfTheReaders = array_keys(static::PLACEHOLDERS_FILLED_BY_THE_READER);
        sort($promptNames);
        sort($promptNamesOfTheReaders);

        static::assertSame($promptNames, $promptNamesOfTheReaders);
    }

    #[DataProvider('providePromptNames')]
    public function testEveryPlaceholderOfAFileIsOneItsReaderFills(string $promptName): void
    {
        preg_match_all('/\{%([A-Z_]+)%\}/', (string)file_get_contents(static::getPromptFilePath($promptName)), $placeholderMatches);
        $placeholdersOfTheFile = array_values(array_unique(array_diff($placeholderMatches[1], [AgentVoicePromptText::PARAMETER_AGENT_NAME])));
        $placeholdersOfTheReader = static::PLACEHOLDERS_FILLED_BY_THE_READER[$promptName];
        sort($placeholdersOfTheFile);
        sort($placeholdersOfTheReader);

        static::assertSame($placeholdersOfTheReader, $placeholdersOfTheFile);
    }

    #[DataProvider('providePromptNames')]
    public function testNoTextIsLeftWithAPlaceholderOnceItsReaderFillsIt(string $promptName): void
    {
        $parameters = [];
        foreach (static::PLACEHOLDERS_FILLED_BY_THE_READER[$promptName] as $placeholderName) {
            $parameters[$placeholderName] = 'value';
        }

        static::assertStringNotContainsString('{%', AgentVoicePromptText::forName($promptName, $parameters));
    }

    /** @return array<string, array{string}> */
    public static function providePromptFiles(): array
    {
        $promptFiles = [];
        foreach ([...(array)glob(static::getPromptsDirectory() . '/AI/Voice/*.md'), static::getPromptsDirectory() . '/AI/VoiceCalls.md'] as $promptFilePath) {
            $promptFiles[basename((string)$promptFilePath)] = [(string)$promptFilePath];
        }

        return $promptFiles;
    }

    #[DataProvider('providePromptFiles')]
    public function testNoFileNamesAPhoneLineOrALocation(string $promptFilePath): void
    {
        $promptFileText = (string)file_get_contents($promptFilePath);

        static::assertSame(0, preg_match(static::FORBIDDEN_WORDS_PATTERN, $promptFileText, $forbiddenWordMatch), basename($promptFilePath) . ' names "' . ($forbiddenWordMatch[0] ?? '') . '"');
        static::assertStringNotContainsString('Nova', $promptFileText, 'the agent name is never written into a file');
    }

    public function testTheBillingAnchorOfTheSessionSecondsIsFound(): void
    {
        static::assertSame('AI.VoiceCalls', AgentVoiceCallAdmissionService::PROMPT_NAME_SESSION);
        static::assertFileExists(static::getPromptFilePath(AgentVoiceCallAdmissionService::PROMPT_NAME_SESSION));
        static::assertStringStartsWith('Billing anchor of voice sessions:', AgentVoicePromptText::forName(AgentVoiceCallAdmissionService::PROMPT_NAME_SESSION));
    }

    public function testTheAgentsNameComesFromThePersona(): void
    {
        static::assertSame(
            "The owner opened this call from inside the application and is signed in (account 5).\nYou are Nova, the owner's digital assistant. Confirm briefly and warmly that you are connected, then ask what this is about. Open the call yourself — do not wait for the caller to speak first.",
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING, ['ACCOUNT_ID' => 5])
        );
    }

    /** @return array<string, array{array<string, string>}> */
    public static function providePersonasWithoutAName(): array
    {
        return [
            'an empty name' => [['agentName' => '', 'productName' => 'the workbench']],
            'a blank name' => [['agentName' => '   ', 'productName' => 'the workbench']],
            'no name' => [['productName' => 'the workbench']],
        ];
    }

    /** @param array<string, string> $agentPersonaConfigEntries */
    #[DataProvider('providePersonasWithoutAName')]
    public function testAPersonaWithoutANameGivesTheNeutralLabel(array $agentPersonaConfigEntries): void
    {
        $this->declareAgentPersonaConfiguration($agentPersonaConfigEntries);

        static::assertSame(
            "You are the agent, the owner's digital assistant. The owner reached you from inside the application and is signed in. Open the call yourself: greet them briefly and ask what this is about.",
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_BRIEFING_WITHOUT_CONVERSATION)
        );
    }

    public function testAMissingPromptIsAnEmptyTextAndValuesAreFilledInOnePass(): void
    {
        static::assertSame('', AgentVoicePromptText::forName('AI.Voice.NoSuchPromptOfTheTest'));
        static::assertSame(
            "Your task for this call:\nAsk {%AGENT_NAME%} about {%LATER%} and 3.5\nState the reason for the call right in your opening and work the task through directly; anything about the owner's data goes to the backend as always. Summarize at the end what was done or agreed.",
            AgentVoicePromptText::forName(AgentVoiceAIPromptNames::APP_AI_VOICE_CALL_TASK, ['CALL_TASK' => 'Ask {%AGENT_NAME%} about {%LATER%} and 3.5', 'LATER' => 1])
        );
    }
}
