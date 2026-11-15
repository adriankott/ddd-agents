<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Config;

use DDD\Domain\AI\Entities\Conversations\Agent\Prompts\AgentAIPromptNames;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Infrastructure\Services\DDDService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The package's default prompts of a judged run: the simulated owner (driver) and the judge (verdict) of a judged run
 * and of a compaction-tree run. Each file loads by its name constant, declares exactly the placeholders the judged-run
 * service fills, and carries no word of an application.
 *
 * The service fills the placeholders in this order (`AIPrompt::getPromptTextWithParametersApplied()` replaces in the
 * order the parameters were set, so a value set later is never rewritten by a value set earlier; the texts of the
 * conversation — the transcript, the tree texts and the trace — come last):
 * - Driver: GOAL, FACTS, TRANSCRIPT
 * - Verdict: GOAL, OPENING, RUBRIC, HOST_RULES, HOST_SECTIONS, TRACE
 * - CompactionTreeDriver: GOAL, FACTS, COMPACTION_PARAMETERS, TRANSCRIPT
 * - CompactionTreeVerdict: COMPACTION_PARAMETERS, GOAL, RUBRIC, TREE_EVOLUTION, FINAL_TREE, TRACE
 *
 * The package's file is the default; an application's file at the same path replaces it whole and declares the same
 * placeholders.
 */
class AgentEvaluationPromptFilesTest extends TestCase
{
    protected const string PACKAGE_PROMPTS_DIRECTORY = __DIR__ . '/../../config/app/AI/Prompts';

    /** Prompt name => its placeholders in the order the service fills them. */
    protected const array PLACEHOLDERS_IN_FILL_ORDER = [
        AgentAIPromptNames::APP_AI_AGENT_EVALUATION_DRIVER => ['GOAL', 'FACTS', 'TRANSCRIPT'],
        AgentAIPromptNames::APP_AI_AGENT_EVALUATION_VERDICT => ['GOAL', 'OPENING', 'RUBRIC', 'HOST_RULES', 'HOST_SECTIONS', 'TRACE'],
        AgentAIPromptNames::APP_AI_AGENT_EVALUATION_COMPACTION_TREE_DRIVER => ['GOAL', 'FACTS', 'COMPACTION_PARAMETERS', 'TRANSCRIPT'],
        AgentAIPromptNames::APP_AI_AGENT_EVALUATION_COMPACTION_TREE_VERDICT => ['COMPACTION_PARAMETERS', 'GOAL', 'RUBRIC', 'TREE_EVOLUTION', 'FINAL_TREE', 'TRACE'],
    ];

    /**
     * Words a text of the module never carries: the words of one application for the owner's space and its products,
     * "card" for a widget, and German.
     */
    protected const array APPLICATION_WORD_PATTERNS = [
        '/\blocations?\b/i',
        '/\bprojects?\b/i',
        '/\bbusiness(es)?\b/i',
        '/\bresellers?\b/i',
        '/\bset-?up\b/i',
        '/\bcards?\b/i',
        '/\bkeywords?\b/i',
        '/\brankings?\b/i',
        '/[äöüÄÖÜß]/u',
        '/\b(und|oder|nicht|der|die|das|ist|eine?|du|dein|Nutzer|Karte|Verstoß|Rückfrage)\b/u',
    ];

    /** The keys the verdict parser reads, in the order the verdict prompt asks for them (the scalars lead). */
    protected const array VERDICT_KEYS = ['verdict', 'scorePercent', 'criteria', 'goalMet', 'grounded', 'toolAppropriate', 'clarificationOk', 'ungroundedClaims', 'reasons'];

    /** The keys of the compaction-tree verdict, in the order the prompt asks for them. */
    protected const array COMPACTION_TREE_VERDICT_KEYS = ['verdict', 'treeGrew', 'rollUpsFormed', 'recencyStaircase', 'noGapsOrOverlaps', 'factSurvived', 'reasons'];

    /** @return array<string, array{string}> */
    public static function evaluationPromptNames(): array
    {
        $promptNames = [];
        foreach (array_keys(self::PLACEHOLDERS_IN_FILL_ORDER) as $promptName) {
            $promptNames[$promptName] = [$promptName];
        }
        return $promptNames;
    }

    #[DataProvider('evaluationPromptNames')]
    public function testThePromptLoadsByItsNameAndIsThePackagesFile(string $promptName): void
    {
        $aIPrompt = $this->findPromptByName($promptName);
        self::assertSame($promptName, $aIPrompt->name);
        self::assertSame((string)file_get_contents($this->buildPromptFilePath($promptName)), $aIPrompt->promptText);
    }

    #[DataProvider('evaluationPromptNames')]
    public function testThePromptDeclaresExactlyThePlaceholdersTheServiceFills(string $promptName): void
    {
        $promptText = $this->findPromptByName($promptName)->promptText;
        preg_match_all('/\{%([A-Z_]+)%\}/', $promptText, $placeholderMatches);
        $declaredPlaceholders = $placeholderMatches[1];
        self::assertSame(count($declaredPlaceholders), count(array_unique($declaredPlaceholders)), $promptName . ': each placeholder once');
        $expectedPlaceholders = self::PLACEHOLDERS_IN_FILL_ORDER[$promptName];
        sort($declaredPlaceholders);
        sort($expectedPlaceholders);
        self::assertSame($expectedPlaceholders, $declaredPlaceholders, $promptName);
    }

    #[DataProvider('evaluationPromptNames')]
    public function testFilledInTheServicesOrderNoPlaceholderIsLeftAndTheLastValueStaysVerbatim(string $promptName): void
    {
        $aIPrompt = $this->findPromptByName($promptName);
        $placeholdersInFillOrder = self::PLACEHOLDERS_IN_FILL_ORDER[$promptName];
        $lastPlaceholder = $placeholdersInFillOrder[array_key_last($placeholdersInFillOrder)];
        // the conversation's text, filled last, quotes every placeholder of the file: none of them is rewritten
        $conversationText = 'conversation text quoting';
        foreach ($placeholdersInFillOrder as $placeholder) {
            $conversationText .= ' {%' . $placeholder . '%}';
        }
        foreach ($placeholdersInFillOrder as $placeholder) {
            $aIPrompt->setParameter($placeholder, $placeholder === $lastPlaceholder ? $conversationText : 'value of ' . $placeholder);
        }
        $filledPromptText = $aIPrompt->getPromptTextWithParametersApplied();
        self::assertStringContainsString($conversationText, $filledPromptText);
        self::assertSame(0, preg_match('/\{%[A-Z_]+%\}/', str_replace($conversationText, '', $filledPromptText)), $promptName);
        foreach ($placeholdersInFillOrder as $placeholder) {
            if ($placeholder !== $lastPlaceholder) {
                self::assertStringContainsString('value of ' . $placeholder, $filledPromptText);
            }
        }
    }

    #[DataProvider('evaluationPromptNames')]
    public function testThePromptCarriesNoWordOfAnApplication(string $promptName): void
    {
        $promptText = $this->findPromptByName($promptName)->promptText;
        foreach (self::APPLICATION_WORD_PATTERNS as $applicationWordPattern) {
            self::assertSame(0, preg_match($applicationWordPattern, $promptText, $applicationWordMatch), $promptName . ': ' . ($applicationWordMatch[0] ?? ''));
        }
    }

    public function testTheVerdictCarriesTheTwoPlacesOfTheApplicationsRulesAndSections(): void
    {
        $verdictText = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_VERDICT)->promptText;
        $tracePosition = strpos($verdictText, '{%TRACE%}');
        $hostSectionsPosition = strpos($verdictText, '{%HOST_SECTIONS%}');
        $legendPosition = strpos($verdictText, 'HOW TO READ THE TRACE');
        $minorListPosition = strpos($verdictText, '- MINOR (path/UX');
        $hostRulesPosition = strpos($verdictText, '{%HOST_RULES%}');
        $requiredVersusAdvisoryPosition = strpos($verdictText, '- REQUIRED vs ADVISORY');
        foreach ([$tracePosition, $hostSectionsPosition, $legendPosition, $minorListPosition, $hostRulesPosition, $requiredVersusAdvisoryPosition] as $position) {
            self::assertNotFalse($position);
        }
        // the application's sections follow the trace and precede the legend that explains it
        self::assertGreaterThan($tracePosition, $hostSectionsPosition);
        self::assertLessThan($legendPosition, $hostSectionsPosition);
        // the application's rules close the minor list, before the last rule of the classes
        self::assertGreaterThan($minorListPosition, $hostRulesPosition);
        self::assertLessThan($requiredVersusAdvisoryPosition, $hostRulesPosition);
        // each on a line of its own, so an empty value leaves no broken sentence
        self::assertMatchesRegularExpression('/^\{%HOST_SECTIONS%\}$/m', $verdictText);
        self::assertMatchesRegularExpression('/^\{%HOST_RULES%\}$/m', $verdictText);
    }

    public function testAnApprovedDraftValueMissingFromTheEndStateIsAHardViolation(): void
    {
        $verdictText = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_VERDICT)->promptText;
        $hardListStart = strpos($verdictText, '- HARD (devalues the result)');
        $minorListStart = strpos($verdictText, '- MINOR (path/UX');
        self::assertNotFalse($hardListStart);
        self::assertNotFalse($minorListStart);
        $hardList = (string)preg_replace('/\s+/', ' ', substr($verdictText, $hardListStart, $minorListStart - $hardListStart));
        self::assertStringContainsString('a VALUE from the draft the owner approved that is MISSING from the END STATE', $hardList);
        self::assertStringContainsString('check every line of the approved draft against the end state', $hardList);
    }

    public function testAWidgetTheOwnerAskedToSeeAgainIsNoViolation(): void
    {
        $verdictText = (string)preg_replace('/\s+/', ' ', $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_VERDICT)->promptText);
        self::assertStringContainsString('NO violation: showing again a widget or a form the owner asked to see again', $verdictText);
    }

    public function testAnOutwardActionWithoutTheOwnersYesStaysHardAndACorrectedErrorIsMet(): void
    {
        $verdictText = (string)preg_replace('/\s+/', ' ', $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_VERDICT)->promptText);
        self::assertStringContainsString('EXCEPTION WITHOUT CURE: an OUTWARD action without the owner\'s explicit yes in an owner line before it', $verdictText);
        self::assertStringContainsString('the criterion has status "met" with the note "corrected"', $verdictText);
        self::assertStringContainsString('a minor slip NEVER sets the verdict to FAIL', $verdictText);
        self::assertStringContainsString('`verdict`: PASS exactly when NO REQUIRED criterion is violated', $verdictText);
    }

    public function testTheVerdictAsksForTheKeysTheParserReadsInTheirOrder(): void
    {
        $verdictText = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_VERDICT)->promptText;
        self::assertSame(self::VERDICT_KEYS, $this->findTopLevelKeysOfTheAnswerShape($verdictText));
        self::assertStringContainsString('"criteria":[{"name":"...","status":"met|violated|partial","note":"one line"}]', $verdictText);
        self::assertStringContainsString('"verdict":"PASS|FAIL","scorePercent":0-100', $verdictText);
        self::assertStringContainsString('Answer with EXACTLY ONE JSON object, nothing before or after it:', $verdictText);
    }

    public function testTheCompactionTreeVerdictAsksForItsKeysInTheirOrder(): void
    {
        $verdictText = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_COMPACTION_TREE_VERDICT)->promptText;
        self::assertSame(self::COMPACTION_TREE_VERDICT_KEYS, $this->findTopLevelKeysOfTheAnswerShape($verdictText));
        self::assertStringContainsString('Answer with EXACTLY ONE JSON object, nothing before or after it:', $verdictText);
    }

    public function testTheSimulatedOwnerAsksForNothingBeyondItsGoalOrAgainstItsFacts(): void
    {
        $driverText = (string)preg_replace('/\s+/', ' ', $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_DRIVER)->promptText);
        self::assertStringContainsString('you ask for nothing that goes beyond YOUR GOAL and for nothing that contradicts YOUR FACTS', $driverText);
        self::assertStringContainsString('You never raise a new task that is not in YOUR GOAL.', $driverText);
        self::assertStringNotContainsString('or the next question', $driverText);
    }

    public function testTheSimulatedOwnersEndMarkersAreTheOnesTheRunReads(): void
    {
        $driverText = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_DRIVER)->promptText;
        self::assertStringContainsString('answer EXACTLY "[DONE]"', $driverText);
        self::assertStringContainsString('answer EXACTLY "[GIVE-UP]"', $driverText);
        $compactionTreeDriverText = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_COMPACTION_TREE_DRIVER)->promptText;
        self::assertStringContainsString('"[GIVE-UP]"', $compactionTreeDriverText);
        self::assertStringContainsString('Do NOT end early', $compactionTreeDriverText);
    }

    public function testGoalFactsAndTranscriptReachTheSimulatedOwner(): void
    {
        $aIPrompt = $this->findPromptByName(AgentAIPromptNames::APP_AI_AGENT_EVALUATION_DRIVER);
        $aIPrompt->setParameter('GOAL', 'the goal');
        $aIPrompt->setParameter('FACTS', 'Active space. Change nothing.');
        $aIPrompt->setParameter('TRANSCRIPT', 'the transcript');
        $driverText = $aIPrompt->getPromptTextWithParametersApplied();
        self::assertStringContainsString('YOUR GOAL: the goal', $driverText);
        self::assertStringContainsString('Active space. Change nothing.', $driverText);
        self::assertStringContainsString('the transcript', $driverText);
    }

    protected function findPromptByName(string $promptName): AIPrompt
    {
        /** @var AIPromptsService $aIPromptsService */
        $aIPromptsService = DDDService::instance()->getService(AIPromptsService::class);
        $aIPrompt = $aIPromptsService->getAIPromptByName($promptName);
        self::assertInstanceOf(AIPrompt::class, $aIPrompt, $promptName);
        return $aIPrompt;
    }

    protected function buildPromptFilePath(string $promptName): string
    {
        return self::PACKAGE_PROMPTS_DIRECTORY . '/' . str_replace('.', '/', $promptName) . '.md';
    }

    /**
     * The top-level keys of the JSON answer shape at the end of a verdict prompt, in order (a key inside the criteria
     * list is not top-level).
     *
     * @return string[]
     */
    protected function findTopLevelKeysOfTheAnswerShape(string $promptText): array
    {
        $answerShape = substr($promptText, (int)strrpos($promptText, '{"verdict"'));
        $answerShapeWithoutCriteriaEntries = (string)preg_replace('/\[\{[^\]]*\}\]/', '[]', $answerShape);
        preg_match_all('/"([A-Za-z]+)":/', $answerShapeWithoutCriteriaEntries, $keyMatches);
        return $keyMatches[1];
    }
}
