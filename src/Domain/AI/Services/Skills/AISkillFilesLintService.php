<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\Conversations\Purposes\AIConversationPurpose;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * The skill-file lint: what breaks a skill or purpose file at runtime without any warning, checked over
 * every file of the given roots — a module's files included when an application overrides them (the module's file
 * still ships and loads in another application). Every problem is one line naming the file and the cause; an empty
 * list = clean. Run by the package's test over its own root and by an application's test over every registered root
 * ({@see AISkillSourcesService}).
 *
 * Skill files: the frontmatter parses as YAML (a double-quoted value with an unescaped inner double quote breaks every
 * run that loads the skill), names a description, carries only the keys the loader reads
 * ({@see AISkillsService::SKILL_FRONTMATTER_KEYS} — a misspelt gate is silently no gate), its lists are lists of
 * strings and its sub-skills resolve. Purpose files: the frontmatter parses and names a valid purpose class. The
 * binding (`AI.skillPackages`): every package id resolves, every bound class is an MCP package, every catalog skill has
 * a file, and no tool name is provided by two different packages. Names: no two files collide case-insensitively.
 *
 * The naming rules of the framework (skill and package id):
 * - a skill name has two or three parts in upper camel case (`<Topic>.<Feature>`, `Agent.Web.Search`); no part
 *   repeats its topic, no part joins two subjects with `And`;
 * - no skill file lies beside a folder of the same name, in any config root (the config reads the file, never the
 *   folder);
 * - a package id is `<provider>.<topic>.<feature>` in lower camel case; the provider of an application's package is
 *   the configured {@see self::CONFIG_PACKAGE_ID_PROVIDER}, a package of the framework or a module names its own word
 *   (`ddd`, `agent`, `clouddrive`); never an audience word ({@see self::AUDIENCE_WORDS});
 * - a package bound by exactly one skill carries that skill's words as its id (`Agent.Memory` ↔ `agent.memory`,
 *   `System.Health` ↔ `ddd.system.health`);
 * - the binding names classes, never ids;
 * - a right a skill requires is required by every package it binds (`MCPRequiresAccountRights` on the class).
 *
 * An application with older names lists them in {@see self::CONFIG_LINT_ALLOW_LIST} (skill names and package ids):
 * their naming problems are not reported, and an entry that no longer has one is reported itself, so the list only
 * shrinks.
 */
class AISkillFilesLintService extends Service
{
    /** @var string[] The skill frontmatter keys whose value must be a list of strings. */
    public const array SKILL_FRONTMATTER_LIST_KEYS = [
        'requiredFeatureFlags', 'anyOfRequiredFeatureFlags', 'requiredAccountRights', 'subSkillNames',
        'onlyUnderPurposeNames', 'notUnderPurposeNames', 'preloadOnWidgetTypes', 'providesWidgetTypes', 'onlyOnInitiatorTypes',
    ];

    /** @var string The config of the application's provider word of its package ids (`rb`, `rc`, `df`) */
    public const string CONFIG_PACKAGE_ID_PROVIDER = 'AI.packageIdProvider';

    /** @var string The config of the skill names and package ids exempt from the naming rules (a list that only shrinks) */
    public const string CONFIG_LINT_ALLOW_LIST = 'AI.skillLintAllowList';

    /** @var string[] Words that name an audience, never a provider */
    public const array AUDIENCE_WORDS = ['admin', 'client', 'public', 'partner', 'batch'];

    /** @var string A part of a skill name: upper camel case */
    public const string SKILL_NAME_PART_PATTERN = '/^[A-Z][A-Za-z0-9]*$/';

    /** @var string A package id: two to four parts in lower camel case */
    public const string PACKAGE_ID_PATTERN = '/^[a-z][A-Za-z0-9]*(\.[a-z][A-Za-z0-9]*){1,3}$/';

    /**
     * The naming problems of a skill name: the number and the case of its parts, a part that repeats the topic, a part
     * that joins two subjects with `And`. Pure.
     *
     * @return string[]
     */
    public static function findSkillNameProblems(string $skillName): array
    {
        $nameParts = explode('.', $skillName);
        $problems = [];
        if (count($nameParts) < 2 || count($nameParts) > 3) {
            $problems[] = "skill name '$skillName' has " . count($nameParts) . ' parts; a skill is <Topic>.<Feature>, three parts at most';
        }
        foreach ($nameParts as $partIndex => $namePart) {
            if (preg_match(self::SKILL_NAME_PART_PATTERN, $namePart) !== 1) {
                $problems[] = "skill name '$skillName': part '$namePart' is not in upper camel case";
            }
            if ($partIndex > 0 && strcasecmp($namePart, $nameParts[0]) === 0) {
                $problems[] = "skill name '$skillName': the feature repeats the topic '{$nameParts[0]}'";
            }
            if (preg_match('/And(?=[A-Z]|$)/', $namePart) === 1) {
                $problems[] = "skill name '$skillName': part '$namePart' joins two subjects with 'And'; one subject, one skill";
            }
        }
        return $problems;
    }

    /** The words of a skill as a package id writes them: `Agent.Web.Search` → `agent.web.search`, `Agent.UI` → `agent.ui`. Pure. */
    public static function buildPackageIdWordsOfSkillName(string $skillName): string
    {
        $idParts = [];
        foreach (explode('.', $skillName) as $namePart) {
            $idParts[] = preg_match('/^[A-Z0-9]+$/', $namePart) === 1 ? strtolower($namePart) : lcfirst($namePart);
        }
        return implode('.', $idParts);
    }

    /**
     * The naming problems of a package id: its form, its provider, and — for a package one skill binds — the words of
     * that skill. `$requiredProvider` null: any provider but an audience word. Pure.
     *
     * @return string[]
     */
    public static function findPackageIdProblems(string $packageId, ?string $requiredProvider, ?string $onlyBindingSkillName): array
    {
        if (preg_match(self::PACKAGE_ID_PATTERN, $packageId) !== 1) {
            return ["package id '$packageId' is not <provider>.<topic>.<feature> in lower camel case"];
        }
        $problems = [];
        $provider = explode('.', $packageId)[0];
        if (in_array($provider, self::AUDIENCE_WORDS, true)) {
            $problems[] = "package id '$packageId' starts with the audience word '$provider'; the provider is the application's word, 'ddd' or a module's word";
        } elseif ($requiredProvider !== null && $provider !== $requiredProvider) {
            $problems[] = "package id '$packageId' does not start with the application's provider '$requiredProvider' (" . self::CONFIG_PACKAGE_ID_PROVIDER . ')';
        }
        if ($onlyBindingSkillName !== null) {
            $skillWords = self::buildPackageIdWordsOfSkillName($onlyBindingSkillName);
            if ($packageId !== $skillWords && $packageId !== $provider . '.' . $skillWords) {
                $problems[] = "package id '$packageId' is bound by the one skill '$onlyBindingSkillName' and does not carry its words ('$skillWords' or '<provider>.$skillWords')";
            }
        }
        return $problems;
    }

    /** @return string[] The skill names and package ids exempt from the naming rules ({@see self::CONFIG_LINT_ALLOW_LIST}) */
    public function getLintAllowList(): array
    {
        $allowList = Config::get(self::CONFIG_LINT_ALLOW_LIST);
        return is_array($allowList) ? array_values(array_filter($allowList, 'is_string')) : [];
    }

    /**
     * The naming problems of every skill name below the given skill folders, and every skill file that lies beside a
     * folder of the same name in any of them; allow-list entries without a problem are reported too.
     *
     * @param string[] $skillRootDirectories
     * @return string[]
     */
    public function findSkillNamingProblems(array $skillRootDirectories): array
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        $allowList = $this->getLintAllowList();
        $problems = [];
        foreach ($skillRootDirectories as $skillRootDirectory) {
            foreach ($aISkillSourcesService->findDottedNamesInFolder($skillRootDirectory) as $skillName) {
                $skillFile = $skillRootDirectory . '/' . str_replace('.', '/', $skillName) . '.md';
                $skillNameProblems = self::findSkillNameProblems($skillName);
                if (in_array($skillName, $allowList, true)) {
                    if ($skillNameProblems === []) {
                        $problems[] = "lint allow list: '$skillName' has no naming problem any more; remove it from " . self::CONFIG_LINT_ALLOW_LIST;
                    }
                    $skillNameProblems = [];
                }
                foreach ($skillNameProblems as $skillNameProblem) {
                    $problems[] = "$skillFile: $skillNameProblem";
                }
                foreach ($skillRootDirectories as $otherSkillRootDirectory) {
                    $folderOfTheSameName = $otherSkillRootDirectory . '/' . str_replace('.', '/', $skillName);
                    if (is_dir($folderOfTheSameName)) {
                        $problems[] = "$skillFile: lies beside the folder $folderOfTheSameName of the same name (the config reads the file, never the folder)";
                    }
                }
            }
        }
        return $problems;
    }

    /**
     * The naming problems of the effective binding: the binding names an id instead of a class, a package id of the
     * wrong form or provider, a package one skill binds without that skill's words, a right of a skill that a bound
     * package does not require. Allow-list entries without a problem are reported too.
     *
     * @return string[]
     */
    public function findBindingNamingProblems(): array
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        try {
            $packageClassesBySkillName = AISkill::getPackageClassesBySkillName();
        } catch (Throwable $bindingError) {
            return ['skill binding: ' . $bindingError->getMessage()];
        }
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $allowList = $this->getLintAllowList();
        $usedAllowListEntries = [];
        $problems = [];
        foreach (is_array($configuredBinding) ? $configuredBinding : [] as $skillName => $configuredPackageReferences) {
            foreach ((array)$configuredPackageReferences as $configuredPackageReference) {
                if (is_string($configuredPackageReference) && !str_contains($configuredPackageReference, '\\')) {
                    if (in_array((string)$skillName, $allowList, true)) {
                        $usedAllowListEntries[(string)$skillName] = true;
                        continue;
                    }
                    $problems[] = "skill binding: '$skillName' names the package id '$configuredPackageReference'; bind the class";
                }
            }
        }
        $skillNamesByPackageClass = [];
        foreach ($packageClassesBySkillName as $skillName => $packageClasses) {
            foreach ($packageClasses as $packageClass) {
                $skillNamesByPackageClass[$packageClass][] = (string)$skillName;
            }
        }
        $configuredProvider = Config::get(self::CONFIG_PACKAGE_ID_PROVIDER);
        $configuredProvider = is_string($configuredProvider) && $configuredProvider !== '' ? $configuredProvider : null;
        foreach ($skillNamesByPackageClass as $packageClass => $skillNames) {
            if (!class_exists($packageClass) || !is_subclass_of($packageClass, MCPPackage::class)) {
                continue;
            }
            $packageId = (string)($packageClass::$name ?? '');
            $isFrameworkPackage = str_starts_with($packageClass, 'DDD\\');
            $packageIdProblems = self::findPackageIdProblems(
                $packageId,
                $isFrameworkPackage ? null : $configuredProvider,
                count($skillNames) === 1 ? $skillNames[0] : null
            );
            if ($packageIdProblems !== [] && in_array($packageId, $allowList, true)) {
                $usedAllowListEntries[$packageId] = true;
                $packageIdProblems = [];
            }
            foreach ($packageIdProblems as $packageIdProblem) {
                $problems[] = "skill binding: $packageIdProblem ($packageClass)";
            }
            $requiredRightNamesOfPackage = MCPPackage::findRequiredAccountRightNamesOfClass($packageClass);
            foreach ($skillNames as $skillName) {
                $aISkill = $aISkillsService->getAISkillByName($skillName);
                foreach ($aISkill !== null ? $aISkill->requiredAccountRights : [] as $requiredRightName) {
                    if (!in_array($requiredRightName, $requiredRightNamesOfPackage, true)) {
                        $problems[] = "skill binding: '$skillName' requires the right '$requiredRightName', which its package $packageClass does not require (#[MCPRequiresAccountRights]): the tools would run without it";
                    }
                }
            }
        }
        foreach ($allowList as $allowListEntry) {
            if (str_contains($allowListEntry, '.') && preg_match('/^[a-z]/', $allowListEntry) === 1 && !isset($usedAllowListEntries[$allowListEntry])) {
                $problems[] = "lint allow list: '$allowListEntry' has no naming problem any more; remove it from " . self::CONFIG_LINT_ALLOW_LIST;
            }
        }
        return $problems;
    }

    /**
     * Every problem of the skill files below the given skill folders.
     *
     * @param string[] $skillRootDirectories
     * @return string[]
     */
    public function findSkillFileProblems(array $skillRootDirectories): array
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $problems = [];
        $skillNamesByRoot = [];
        foreach ($skillRootDirectories as $skillRootDirectory) {
            $skillNamesByRoot[$skillRootDirectory] = $aISkillSourcesService->findDottedNamesInFolder($skillRootDirectory);
            foreach ($skillNamesByRoot[$skillRootDirectory] as $skillName) {
                $skillFile = $skillRootDirectory . '/' . str_replace('.', '/', $skillName) . '.md';
                $frontmatter = $this->parseFrontmatterOfFile($skillFile, $aISkillsService, $problems);
                if ($frontmatter === null) {
                    continue;
                }
                if (trim((string)($frontmatter['description'] ?? '')) === '') {
                    $problems[] = "$skillFile: no description (the only routing signal of a skill)";
                }
                foreach (array_diff(array_keys($frontmatter), AISkillsService::SKILL_FRONTMATTER_KEYS) as $unknownKey) {
                    $problems[] = "$skillFile: unknown frontmatter key '$unknownKey' (the loader ignores it)";
                }
                foreach (self::SKILL_FRONTMATTER_LIST_KEYS as $listKey) {
                    if (array_key_exists($listKey, $frontmatter) && !$this->isListOfStrings($frontmatter[$listKey])) {
                        $problems[] = "$skillFile: '$listKey' is not a list of strings";
                    }
                }
                if (array_key_exists('turnMinimumSteps', $frontmatter)
                    && AISkill::readTurnMinimumStepsOfFrontmatter($frontmatter['turnMinimumSteps']) === null
                ) {
                    $problems[] = "$skillFile: 'turnMinimumSteps' is not a positive whole number (the loader ignores it)";
                }
                if (array_key_exists('subSkillOf', $frontmatter)) {
                    array_push($problems, ...$this->findSubSkillOfProblems($skillFile, $skillName, $frontmatter, $aISkillsService));
                }
                foreach ((array)($frontmatter['subSkillNames'] ?? []) as $subSkillName) {
                    if (is_string($subSkillName) && $aISkillsService->getAISkillByName($subSkillName) === null) {
                        $problems[] = "$skillFile: sub-skill '$subSkillName' resolves to no skill file";
                    }
                }
            }
        }
        foreach ($aISkillSourcesService->findCollidingNames($skillNamesByRoot) as $lowercaseSkillName => $holders) {
            $problems[] = "skill name '$lowercaseSkillName' collides (case-insensitive): " . implode(', ', $holders);
        }
        return $problems;
    }

    /**
     * The problems of a skill file's `subSkillOf`: not a name or a list of names, naming the skill itself, naming a
     * skill without a file, or set beside `isSubSkill: false` (the key makes it a sub-skill).
     *
     * @param array<string, mixed> $frontmatter
     * @return string[]
     */
    public function findSubSkillOfProblems(string $skillFile, string $skillName, array $frontmatter, AISkillsService $aISkillsService): array
    {
        $problems = [];
        $subSkillOfValue = $frontmatter['subSkillOf'];
        if (!is_string($subSkillOfValue) && !$this->isListOfStrings($subSkillOfValue)) {
            $problems[] = "$skillFile: 'subSkillOf' is neither a skill name nor a list of skill names";
        }
        $rootSkillNames = AISkill::readSubSkillOfFrontmatter($subSkillOfValue);
        if ($rootSkillNames === []) {
            $problems[] = "$skillFile: 'subSkillOf' names no root skill";
        }
        foreach ($rootSkillNames as $rootSkillName) {
            if ($rootSkillName === $skillName) {
                $problems[] = "$skillFile: 'subSkillOf' names the skill itself";
            } elseif ($aISkillsService->getAISkillByName($rootSkillName) === null) {
                $problems[] = "$skillFile: 'subSkillOf' root '$rootSkillName' resolves to no skill file";
            }
        }
        if (array_key_exists('isSubSkill', $frontmatter) && $frontmatter['isSubSkill'] === false) {
            $problems[] = "$skillFile: 'subSkillOf' beside 'isSubSkill: false' (subSkillOf makes it a sub-skill; drop isSubSkill)";
        }
        return $problems;
    }

    /**
     * Every problem of the purpose files below the given purpose folders.
     *
     * @param string[] $purposeRootDirectories
     * @return string[]
     */
    public function findPurposeFileProblems(array $purposeRootDirectories): array
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $problems = [];
        $purposeNamesByRoot = [];
        foreach ($purposeRootDirectories as $purposeRootDirectory) {
            $purposeNamesByRoot[$purposeRootDirectory] = $aISkillSourcesService->findDottedNamesInFolder($purposeRootDirectory);
            foreach ($purposeNamesByRoot[$purposeRootDirectory] as $purposeName) {
                $purposeFile = $purposeRootDirectory . '/' . str_replace('.', '/', $purposeName) . '.md';
                $frontmatter = $this->parseFrontmatterOfFile($purposeFile, $aISkillsService, $problems);
                if ($frontmatter === null) {
                    continue;
                }
                $purposeClass = $frontmatter[AIConversationPurposesService::FRONTMATTER_KEY_PURPOSE_CLASS] ?? null;
                if (!is_string($purposeClass) || !class_exists(trim($purposeClass)) || !is_a(trim($purposeClass), AIConversationPurpose::class, true)) {
                    $problems[] = "$purposeFile: no valid purposeClass (the purpose would not load)";
                }
            }
        }
        foreach ($aISkillSourcesService->findCollidingNames($purposeNamesByRoot) as $lowercasePurposeName => $holders) {
            $problems[] = "purpose name '$lowercasePurposeName' collides (case-insensitive): " . implode(', ', $holders);
        }
        return $problems;
    }

    /**
     * Every problem of the effective skill → package binding (`AI.skillPackages` as the running application reads it).
     *
     * @return string[]
     */
    public function findBindingProblems(): array
    {
        try {
            $packageClassesBySkillName = AISkill::getPackageClassesBySkillName();
        } catch (Throwable $bindingError) {
            return ['skill binding: ' . $bindingError->getMessage()];
        }
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $problems = [];
        $packageClassesByToolName = [];
        foreach ($packageClassesBySkillName as $skillName => $packageClasses) {
            if ($aISkillsService->getAISkillByName((string)$skillName) === null) {
                $problems[] = "skill binding: '$skillName' has no skill file in any config root";
            }
            foreach ($packageClasses as $packageClass) {
                if (!class_exists($packageClass) || !is_subclass_of($packageClass, MCPPackage::class)) {
                    $problems[] = "skill binding: '$skillName' binds '$packageClass', which is no MCP package";
                    continue;
                }
                foreach ((new ReflectionClass($packageClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $packageMethod) {
                    if ($packageMethod->getAttributes(MCPTool::class) !== []) {
                        $packageClassesByToolName[$packageMethod->getName()][$packageClass] = true;
                    }
                }
            }
        }
        foreach ($packageClassesByToolName as $toolName => $packageClassSet) {
            if (count($packageClassSet) > 1) {
                $problems[] = "tool name '$toolName' is provided by more than one package: " . implode(', ', array_keys($packageClassSet));
            }
        }
        return $problems;
    }

    /**
     * The frontmatter of a skill or purpose file after the persona substitution, or null when it does not parse (the
     * problem is appended).
     *
     * @param string[] $problems
     * @return array<string, mixed>|null
     */
    protected function parseFrontmatterOfFile(string $configFile, AISkillsService $aISkillsService, array &$problems): ?array
    {
        $rawText = (string)file_get_contents($configFile);
        if (!preg_match('/^\x{FEFF}?\s*---\s*\R/u', $rawText)) {
            $problems[] = "$configFile: no frontmatter block";
            return null;
        }
        try {
            $frontmatter = AISkillsService::splitFrontmatter($aISkillsService->substitutePersonaPlaceholders($rawText))->frontmatter;
        } catch (Throwable $yamlError) {
            $problems[] = "$configFile: the frontmatter is no valid YAML — " . $yamlError->getMessage();
            return null;
        }
        if ($frontmatter === []) {
            $problems[] = "$configFile: the frontmatter is empty or no map";
            return null;
        }
        return $frontmatter;
    }

    public function isListOfStrings(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $element) {
            if (!is_string($element)) {
                return false;
            }
        }
        return true;
    }
}
