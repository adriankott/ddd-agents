<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Installation;

use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentRoleMappedAccountRights;
use DDD\Domain\AI\Entities\Discovery\AgentSourceRoot;
use DDD\Domain\AI\Entities\Installation\AgentFeatureTableConditions;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFinding;
use DDD\Domain\AI\Entities\Installation\AgentInstallationFindings;
use DDD\Domain\AI\Entities\Installation\AgentPackageRename;
use DDD\Domain\AI\Entities\Installation\AgentPackageRenames;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlay;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlays;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Conversations\Discovery\AgentSourceRootsService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AgentDeclaredAccountRightsService;
use DDD\Domain\AI\Services\Conversations\Entitlements\AIConversationEntitlementsService;
use DDD\Domain\AI\Services\Skills\AISkillFilesLintService;
use DDD\Domain\AI\Services\Skills\AISkillSourcesService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Domain\AI\Services\Skills\MCPPackageIdsService;
use DDD\Domain\Base\Repo\DB\Database\DatabaseModel;
use DDD\Domain\Base\Repo\DB\Doctrine\EntityManagerFactory;
use DDD\Domain\Common\Services\EntityModelGeneratorService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Contracts\Service\Attribute\Required;
use Throwable;

/**
 * The installation check of the agent (`app:agent:check-installation`): reads how the application installed the
 * packages and reports what fails at runtime (BLOCKING) or needs a look (WARNING). Reads only: configuration, source
 * files, the console's command list, the database's table list — it writes nothing.
 *
 * Built-in checks (each a protected method, {@see BUILT_IN_CHECK_METHOD_NAMES}):
 * - `unboundPackageSkills` (WARNING): a skill file a package ships that `AI.skillPackages` does not bind — never offered;
 * - `skillBindings` (BLOCKING): a binding names a class that does not exist or is no MCP package, or an id no package has;
 * - `rightsHeldByNobody` (WARNING): a right a package declares that `AI.accountRights` maps to no role, no `'*'` and no
 *   delegation — held by nobody unless the application's rights object holds it;
 * - `accountRightsMapping` (WARNING): the application answers rights with its own object and ships no
 *   `AI.accountRights` of its own — every package right its object does not hold is held by nobody (fail closed; the
 *   package's default mapping grants nothing then);
 * - `skillRights` (WARNING): a bound package requires a right on the class whose skill does not name it in
 *   `requiredAccountRights` — the skill is offered to accounts that may call none of its tools;
 * - `toolOverlays` (WARNING): an entry of `AI.toolOverlays` with a key the overlay does not read, or for a tool name no
 *   bound package has;
 * - `missingTables` (BLOCKING): a table of a package entity the schema lacks (DB unreachable: one WARNING);
 * - `renamedConfigKeys` (WARNING): a config key a package renamed (`renames.json`, kind `configKey`) or removed
 *   ({@see REMOVED_CONFIG_KEYS_BY_VERSION}) still set;
 * - `consoleCommands` (WARNING): a package command name the console does not resolve (only with a console application);
 * - `skillLint` (WARNING; BLOCKING for a skill file of the application that does not parse): the skill-file lint and the
 *   naming lint ({@see AISkillFilesLintService});
 * - `pendingRenames` (WARNING): renames of the installed packages (`renames.json`) newer than the application's record
 *   in `.ddd-renames.json` — `vendor/bin/ddd-renames` applies them.
 *
 * Then every check another package or the application adds through {@see AgentInstallationCheckInterface}. A check
 * that throws becomes one WARNING finding naming it; the others run. A host accepts a known finding in
 * `config/app/AI/installationCheck.php` ({@see CONFIG_PATH}) or skips a whole check (`skippedChecks`); a host adapts a
 * check by a same-path subclass of this service.
 */
class AgentInstallationCheckService extends Service
{
    /** @var string The config of the check: accepted findings and skipped checks */
    public const string CONFIG_PATH = 'AI.installationCheck';

    public const string CHECK_UNBOUND_PACKAGE_SKILLS = 'unboundPackageSkills';

    public const string CHECK_SKILL_BINDINGS = 'skillBindings';

    public const string CHECK_RIGHTS_HELD_BY_NOBODY = 'rightsHeldByNobody';

    /** @var string The check of an application rights object without an application mapping */
    public const string CHECK_ACCOUNT_RIGHTS_MAPPING = 'accountRightsMapping';

    public const string CHECK_SKILL_RIGHTS = 'skillRights';

    public const string CHECK_MISSING_TABLES = 'missingTables';

    public const string CHECK_RENAMED_CONFIG_KEYS = 'renamedConfigKeys';

    public const string CHECK_CONSOLE_COMMANDS = 'consoleCommands';

    public const string CHECK_SKILL_LINT = 'skillLint';

    public const string CHECK_PENDING_RENAMES = 'pendingRenames';

    public const string CHECK_TOOL_OVERLAYS = 'toolOverlays';

    /** @var array<string, string> check name => the protected method that adds its findings, in run order */
    public const array BUILT_IN_CHECK_METHOD_NAMES = [
        self::CHECK_SKILL_BINDINGS => 'addSkillBindingFindings',
        self::CHECK_UNBOUND_PACKAGE_SKILLS => 'addUnboundPackageSkillFindings',
        self::CHECK_RIGHTS_HELD_BY_NOBODY => 'addRightsHeldByNobodyFindings',
        self::CHECK_ACCOUNT_RIGHTS_MAPPING => 'addAccountRightsMappingFindings',
        self::CHECK_SKILL_RIGHTS => 'addSkillRightsFindings',
        self::CHECK_TOOL_OVERLAYS => 'addToolOverlayFindings',
        self::CHECK_MISSING_TABLES => 'addMissingTableFindings',
        self::CHECK_RENAMED_CONFIG_KEYS => 'addRenamedConfigKeyFindings',
        self::CHECK_CONSOLE_COMMANDS => 'addConsoleCommandFindings',
        self::CHECK_SKILL_LINT => 'addSkillLintFindings',
        self::CHECK_PENDING_RENAMES => 'addPendingRenameFindings',
    ];

    /**
     * @var array<string, string[]> release => config keys that release removed without a successor (a renamed key is a
     * `configKey` entry of `renames.json`). Still set in the application = a WARNING of `renamedConfigKeys`.
     */
    public const array REMOVED_CONFIG_KEYS_BY_VERSION = [];

    /** @var string The rename map a package ships at its root */
    public const string RENAMES_FILE_NAME = 'renames.json';

    /** @var string The record of the applied renames in the application's root ({@see addPendingRenameFindings()}) */
    public const string RENAMES_STATE_FILE_NAME = '.ddd-renames.json';

    /** @var string The command that applies the pending renames */
    public const string RENAMES_BIN = 'vendor/bin/ddd-renames';

    /** @var string The folder of a source root that holds its console commands */
    public const string CONSOLE_COMMANDS_FOLDER = 'Symfony/Commands';

    /** @var iterable<AgentInstallationCheckInterface> The added checks (by tag) */
    protected iterable $agentInstallationChecks = [];

    /** @var Application|null The console of the current run; null = the command check is skipped */
    protected ?Application $consoleApplication = null;

    /** @var iterable<AgentFeatureTableConditionProvider> The declared feature tables (by tag) */
    protected iterable $agentFeatureTableConditionProviders = [];

    /** @param iterable<AgentFeatureTableConditionProvider> $agentFeatureTableConditionProviders */
    #[Required]
    public function setAgentFeatureTableConditionProviders(
        #[AutowireIterator(AgentFeatureTableConditionProvider::TAG)]
        iterable $agentFeatureTableConditionProviders
    ): void {
        $this->agentFeatureTableConditionProviders = $agentFeatureTableConditionProviders;
    }

    /** @param iterable<AgentInstallationCheckInterface> $agentInstallationChecks */
    #[Required]
    public function setAgentInstallationChecks(
        #[AutowireIterator(AgentInstallationCheckInterface::TAG)]
        iterable $agentInstallationChecks
    ): void {
        $this->agentInstallationChecks = $agentInstallationChecks;
    }

    /**
     * Runs every check that is not skipped and returns the findings, built-in checks first.
     *
     * @param Application|null $consoleApplication the console whose command names `consoleCommands` checks; null skips it
     * @return AgentInstallationFindings
     */
    public function findInstallationFindings(?Application $consoleApplication = null): AgentInstallationFindings
    {
        $agentInstallationFindings = AgentInstallationFindings::newInstance();
        $skippedCheckNames = $this->readConfiguredNames('skippedChecks');
        $this->consoleApplication = $consoleApplication;
        try {
            foreach (static::BUILT_IN_CHECK_METHOD_NAMES as $checkName => $checkMethodName) {
                if (in_array($checkName, $skippedCheckNames, true)) {
                    continue;
                }
                $this->runBuiltInCheck($checkName, $checkMethodName, $agentInstallationFindings);
            }
            foreach ($this->agentInstallationChecks as $agentInstallationCheck) {
                $checkName = $this->findNameOfAddedCheck($agentInstallationCheck);
                if (in_array($checkName, $skippedCheckNames, true)) {
                    continue;
                }
                try {
                    $agentInstallationFindings->addFindingsOf($agentInstallationCheck->findInstallationFindings());
                } catch (Throwable $checkFailure) {
                    $this->addCheckFailureFinding($agentInstallationFindings, $checkName, $checkFailure);
                }
            }
        } finally {
            $this->consoleApplication = null;
        }
        return $agentInstallationFindings;
    }

    /**
     * Every check name: the built-in ones, then the added ones.
     *
     * @return string[]
     */
    public function getCheckNames(): array
    {
        $checkNames = array_keys(static::BUILT_IN_CHECK_METHOD_NAMES);
        foreach ($this->agentInstallationChecks as $agentInstallationCheck) {
            $checkNames[] = $this->findNameOfAddedCheck($agentInstallationCheck);
        }
        return $checkNames;
    }

    /** Runs one built-in check; a throw becomes one WARNING finding of it */
    protected function runBuiltInCheck(string $checkName, string $checkMethodName, AgentInstallationFindings $agentInstallationFindings): void
    {
        try {
            $this->{$checkMethodName}($agentInstallationFindings);
        } catch (Throwable $checkFailure) {
            $this->addCheckFailureFinding($agentInstallationFindings, $checkName, $checkFailure);
        }
    }

    protected function findNameOfAddedCheck(AgentInstallationCheckInterface $agentInstallationCheck): string
    {
        try {
            return $agentInstallationCheck->getInstallationCheckName();
        } catch (Throwable) {
            return $agentInstallationCheck::class;
        }
    }

    protected function addCheckFailureFinding(AgentInstallationFindings $agentInstallationFindings, string $checkName, Throwable $checkFailure): void
    {
        $firstLineOfReason = strtok(trim($checkFailure->getMessage()), "\n");
        $agentInstallationFindings->addFinding(
            $checkName,
            AgentInstallationFinding::SEVERITY_WARNING,
            $checkName,
            "The check could not run: " . ($firstLineOfReason !== false && $firstLineOfReason !== '' ? $firstLineOfReason : $checkFailure::class),
            'Fix the cause and run the command again; the other checks ran.'
        );
    }

    /**
     * `skillBindings`: every reference of `AI.skillPackages` resolves to an MCP package class (BLOCKING otherwise: the
     * binding throws when the agent reads it, or the skill's tools are missing).
     */
    protected function addSkillBindingFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        if (!is_array($configuredBinding) || $configuredBinding === []) {
            $agentInstallationFindings->addFinding(
                static::CHECK_SKILL_BINDINGS,
                AgentInstallationFinding::SEVERITY_BLOCKING,
                AISkillsService::CONFIG_SKILL_PACKAGES,
                'The skill binding is missing or empty: the agent has no skills.',
                'Ship config/app/AI/skillPackages.php (copy the package file, then change it).'
            );
            return;
        }
        foreach ($configuredBinding as $skillName => $configuredPackageReferences) {
            foreach ((array)$configuredPackageReferences as $configuredPackageReference) {
                $configuredPackageReference = (string)$configuredPackageReference;
                $problem = $this->findProblemOfPackageReference($configuredPackageReference);
                if ($problem !== null) {
                    $agentInstallationFindings->addFinding(
                        static::CHECK_SKILL_BINDINGS,
                        AgentInstallationFinding::SEVERITY_BLOCKING,
                        (string)$skillName,
                        "The skill '$skillName' binds '$configuredPackageReference', $problem.",
                        'Correct or remove the entry in config/app/AI/skillPackages.php (a removed package: see the CHANGELOG of its release).'
                    );
                }
            }
        }
    }

    /** Why a binding reference does not resolve to an MCP package; null when it does */
    protected function findProblemOfPackageReference(string $configuredPackageReference): ?string
    {
        if (str_contains($configuredPackageReference, '\\')) {
            if (!class_exists($configuredPackageReference)) {
                return 'a class that does not exist';
            }
            return is_subclass_of($configuredPackageReference, MCPPackage::class) ? null : 'which is no MCP package';
        }
        /** @var MCPPackageIdsService $mCPPackageIdsService */
        $mCPPackageIdsService = DDDService::instance()->getService(MCPPackageIdsService::class);
        return $mCPPackageIdsService->findPackageClassForId($configuredPackageReference) === null
            ? 'an id no installed MCP package has'
            : null;
    }

    /**
     * `unboundPackageSkills`: a skill file of a package that the binding does not name (WARNING; accepted names:
     * `acceptedUnboundSkills`).
     */
    protected function addUnboundPackageSkillFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        $boundSkillNames = is_array($configuredBinding) ? array_map('strval', array_keys($configuredBinding)) : [];
        $acceptedSkillNames = $this->readConfiguredNames('acceptedUnboundSkills');
        foreach ($this->findPackageSkillNames() as $packageSkillName) {
            if (in_array($packageSkillName, $boundSkillNames, true) || in_array($packageSkillName, $acceptedSkillNames, true)) {
                continue;
            }
            $agentInstallationFindings->addFinding(
                static::CHECK_UNBOUND_PACKAGE_SKILLS,
                AgentInstallationFinding::SEVERITY_WARNING,
                $packageSkillName,
                "An installed package ships the skill '$packageSkillName', which config/app/AI/skillPackages.php does not bind: the agent never offers it.",
                'Bind it (by class or package id) to offer it, or list it in acceptedUnboundSkills of config/app/AI/installationCheck.php.'
            );
        }
    }

    /**
     * The dotted names of the skill files in the config roots of the packages (not the application's), sorted, each once.
     *
     * @return string[]
     */
    protected function findPackageSkillNames(): array
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        $packageSkillNames = [];
        foreach ($aISkillSourcesService->getSkillRootDirectories() as $skillRootDirectory) {
            if ($this->isApplicationPath($skillRootDirectory)) {
                continue;
            }
            foreach ($aISkillSourcesService->findDottedNamesInFolder($skillRootDirectory) as $skillName) {
                $packageSkillNames[$skillName] = true;
            }
        }
        $packageSkillNames = array_keys($packageSkillNames);
        sort($packageSkillNames);
        return array_map('strval', $packageSkillNames);
    }

    /**
     * `rightsHeldByNobody`: a right an installed package declares that the mapping `AI.accountRights` gives to no role,
     * not to `'*'` and to no application right (WARNING; accepted names: `acceptedUnmappedRights`).
     */
    protected function addRightsHeldByNobodyFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        if ($this->isApplicationRightsObjectWithoutApplicationMapping()) {
            return; // the package's default mapping grants nothing then: accountRightsMapping reports it
        }
        /** @var AgentDeclaredAccountRightsService $agentDeclaredAccountRightsService */
        $agentDeclaredAccountRightsService = DDDService::instance()->getService(AgentDeclaredAccountRightsService::class);
        $agentRoleMappedAccountRights = AgentRoleMappedAccountRights::forRoleNamesUnderConfiguredMapping([]);
        $acceptedRightNames = $this->readConfiguredNames('acceptedUnmappedRights');
        foreach ($agentDeclaredAccountRightsService->findDeclaredAccountRights()->getElements() as $agentDeclaredAccountRight) {
            $rightName = $agentDeclaredAccountRight->rightName;
            if (in_array($rightName, $acceptedRightNames, true)
                || $agentRoleMappedAccountRights->getRoleNamesOfRight($rightName) !== []
                || $agentRoleMappedAccountRights->findDelegatedRightName($rightName) !== null
            ) {
                continue;
            }
            $agentInstallationFindings->addFinding(
                static::CHECK_RIGHTS_HELD_BY_NOBODY,
                AgentInstallationFinding::SEVERITY_WARNING,
                $rightName,
                "The right '$rightName' (declared by " . implode(', ', $agentDeclaredAccountRight->declaringClassNames)
                . ') is mapped to no role in AI.accountRights: no account holds it unless the application\'s rights object holds it.',
                "Map it in config/app/AI/accountRights.php (roles, '*', or ['right' => '<application right>']), or list it in acceptedUnmappedRights of config/app/AI/installationCheck.php when the application's rights object answers it."
            );
        }
    }

    /**
     * `accountRightsMapping`: the application answers rights with its own object
     * ({@see AIConversationEntitlementsService::isAccountRightsAnsweredByTheApplication()}) and ships no mapping of its
     * own ({@see AIConversationEntitlementsService::isAccountRightsMappingShippedByTheApplication()}): the package
     * rights its object does not hold are held by nobody. One WARNING naming them.
     */
    protected function addAccountRightsMappingFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        if (!$this->isApplicationRightsObjectWithoutApplicationMapping()) {
            return;
        }
        /** @var AgentDeclaredAccountRightsService $agentDeclaredAccountRightsService */
        $agentDeclaredAccountRightsService = DDDService::instance()->getService(AgentDeclaredAccountRightsService::class);
        $acceptedRightNames = $this->readConfiguredNames('acceptedUnmappedRights');
        $packageRightNames = [];
        foreach ($agentDeclaredAccountRightsService->findDeclaredAccountRights()->getElements() as $agentDeclaredAccountRight) {
            if (!in_array($agentDeclaredAccountRight->rightName, $acceptedRightNames, true)) {
                $packageRightNames[] = $agentDeclaredAccountRight->rightName;
            }
        }
        if ($packageRightNames === []) {
            return;
        }
        sort($packageRightNames);
        $agentInstallationFindings->addFinding(
            static::CHECK_ACCOUNT_RIGHTS_MAPPING,
            AgentInstallationFinding::SEVERITY_WARNING,
            'AI.accountRights',
            'The application answers rights with its own object and ships no config/app/AI/accountRights.php of its own: the package rights '
            . implode(', ', $packageRightNames) . ' are held by nobody unless its rights object holds them (the package\'s default mapping grants nothing then).',
            "Hold them in the application's rights object, or ship config/app/AI/accountRights.php mapping them (roles, '*', or ['right' => '<application right>']); list a right its object answers in acceptedUnmappedRights of config/app/AI/installationCheck.php."
        );
    }

    /** Whether the application answers rights with its own object and has no mapping of its own */
    protected function isApplicationRightsObjectWithoutApplicationMapping(): bool
    {
        /** @var AIConversationEntitlementsService $aIConversationEntitlementsService */
        $aIConversationEntitlementsService = DDDService::instance()->getService(AIConversationEntitlementsService::class);
        return $aIConversationEntitlementsService->isAccountRightsAnsweredByTheApplication()
            && !$aIConversationEntitlementsService->isAccountRightsMappingShippedByTheApplication();
    }

    /**
     * `skillRights`: a bound package requires a right on the class whose skill does not name it in
     * `requiredAccountRights` (WARNING; accepted: skill names or package classes in `acceptedSkillRightGaps`).
     */
    protected function addSkillRightsFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        if (!is_array($configuredBinding)) {
            return;
        }
        try {
            $packageClassesBySkillName = AISkill::resolvePackageIdsOfBinding($configuredBinding);
        } catch (Throwable) {
            return; // reported by skillBindings
        }
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $acceptedGaps = $this->readConfiguredNames('acceptedSkillRightGaps');
        foreach ($packageClassesBySkillName as $skillName => $packageClasses) {
            $skillName = (string)$skillName;
            $aISkill = $aISkillsService->getAISkillByName($skillName);
            if ($aISkill === null || in_array($skillName, $acceptedGaps, true)) {
                continue;
            }
            foreach ($packageClasses as $packageClass) {
                if (!is_subclass_of($packageClass, MCPPackage::class) || in_array($packageClass, $acceptedGaps, true)) {
                    continue;
                }
                $rightNamesTheSkillDoesNotName = array_values(array_diff(
                    MCPPackage::findRequiredAccountRightNamesOfClass($packageClass),
                    $aISkill->requiredAccountRights
                ));
                if ($rightNamesTheSkillDoesNotName === []) {
                    continue;
                }
                $agentInstallationFindings->addFinding(
                    static::CHECK_SKILL_RIGHTS,
                    AgentInstallationFinding::SEVERITY_WARNING,
                    $skillName,
                    "The skill '$skillName' binds $packageClass, whose every tool requires " . implode(', ', $rightNamesTheSkillDoesNotName)
                    . '; the skill does not name it in requiredAccountRights: it is offered to accounts that may call none of its tools.',
                    "Add requiredAccountRights: ['" . implode("', '", $rightNamesTheSkillDoesNotName) . "'] to the skill (its file or config/app/AI/skillRequirements.php), or list the skill or the package class in acceptedSkillRightGaps of config/app/AI/installationCheck.php."
                );
            }
        }
    }

    /**
     * `toolOverlays`: an entry of `AI.toolOverlays` ({@see MCPToolOverlays}) with a key the overlay does not read, or for a
     * tool name no package of the binding has (WARNING: the entry changes nothing).
     */
    protected function addToolOverlayFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        $mcpToolOverlays = MCPToolOverlays::fromConfigEntries(Config::get(MCPToolOverlays::CONFIG_PATH));
        if ($mcpToolOverlays->count() === 0) {
            return;
        }
        $boundToolNames = $this->findBoundToolNames();
        foreach ($mcpToolOverlays->getElements() as $mcpToolOverlay) {
            if ($mcpToolOverlay->unknownKeys !== []) {
                $agentInstallationFindings->addFinding(
                    static::CHECK_TOOL_OVERLAYS,
                    AgentInstallationFinding::SEVERITY_WARNING,
                    $mcpToolOverlay->toolName,
                    "The tool overlay of '{$mcpToolOverlay->toolName}' has key(s) the overlay does not read: " . implode(', ', $mcpToolOverlay->unknownKeys) . '.',
                    'Use only ' . implode(', ', MCPToolOverlay::KEYS) . ' in config/app/AI/toolOverlays.php.'
                );
            }
            if ($boundToolNames !== null && !in_array($mcpToolOverlay->toolName, $boundToolNames, true)) {
                $agentInstallationFindings->addFinding(
                    static::CHECK_TOOL_OVERLAYS,
                    AgentInstallationFinding::SEVERITY_WARNING,
                    $mcpToolOverlay->toolName,
                    "The tool overlay names '{$mcpToolOverlay->toolName}', which no package of the skill binding has: it changes nothing.",
                    'Correct the tool name (a renamed tool: see renames.json of its package) or remove the entry from config/app/AI/toolOverlays.php.'
                );
            }
        }
    }

    /**
     * The tool names of every MCP package of the binding (also of packages unavailable in this process); null when the
     * binding does not resolve (reported by `skillBindings`).
     *
     * @return string[]|null
     */
    protected function findBoundToolNames(): ?array
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        if (!is_array($configuredBinding)) {
            return null;
        }
        try {
            $packageClassesBySkillName = AISkill::resolvePackageIdsOfBinding($configuredBinding);
        } catch (Throwable) {
            return null;
        }
        $boundToolNames = [];
        foreach ($packageClassesBySkillName as $packageClasses) {
            foreach ($packageClasses as $packageClass) {
                if (!is_subclass_of($packageClass, MCPPackage::class)) {
                    continue;
                }
                foreach ((new ReflectionClass($packageClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $packageMethod) {
                    if ($packageMethod->getAttributes(MCPTool::class) !== []) {
                        $boundToolNames[$packageMethod->getName()] = true;
                    }
                }
            }
        }
        return array_map('strval', array_keys($boundToolNames));
    }

    /**
     * `missingTables`: the table of a package entity (or of the application's subclass of one) that the schema lacks
     * (BLOCKING). Tables the application excludes (`Base.Repo.database.excludedTables`), tables in
     * `acceptedMissingTables` and the tables of a feature that is not in use (switched off, or its skill not bound:
     * {@see AgentFeatureTableConditionProvider}, e.g. the memory table while memory is off or a host store answers it)
     * are not required.
     */
    protected function addMissingTableFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        $requiredTableNamesByEntityClass = $this->findRequiredTableNamesByEntityClass();
        if ($requiredTableNamesByEntityClass === []) {
            return;
        }
        try {
            $liveTableNames = $this->findLiveTableNames();
        } catch (Throwable $databaseFailure) {
            // the driver's text is left out: it can name the user, the host or the DSN
            $agentInstallationFindings->addFinding(
                static::CHECK_MISSING_TABLES,
                AgentInstallationFinding::SEVERITY_WARNING,
                'database',
                'The tables could not be checked: the database did not answer (' . (new ReflectionClass($databaseFailure))->getShortName()
                . '; the driver\'s message is not shown, it can name the user, host or DSN).',
                'Run the command where the database is reachable; the application log of the connection names the cause.'
            );
            return;
        }
        $liveTableNamesInLowerCase = array_flip(array_map('strtolower', $liveTableNames));
        foreach ($requiredTableNamesByEntityClass as $entityClassName => $tableName) {
            if (isset($liveTableNamesInLowerCase[strtolower($tableName)])) {
                continue;
            }
            $agentInstallationFindings->addFinding(
                static::CHECK_MISSING_TABLES,
                AgentInstallationFinding::SEVERITY_BLOCKING,
                $tableName,
                "The table '$tableName' of $entityClassName does not exist: the code of the installed version reads it.",
                "Create it (php bin/console app:entity:show-sql " . (new ReflectionClass($entityClassName))->getShortName() . "; the schema diff of the database models), exclude it in Base.Repo.database.excludedTables, or list it in acceptedMissingTables of config/app/AI/installationCheck.php."
            );
        }
    }

    /**
     * entity class => table name of every entity whose class (or a parent class) lies in a package source root, without
     * the tables that are not required (see {@see addMissingTableFindings()}).
     *
     * @return array<string, string>
     */
    protected function findRequiredTableNamesByEntityClass(): array
    {
        $packageSourceRoots = $this->findPackageSourceRoots();
        $acceptedTableNames = array_map('strtolower', $this->readConfiguredNames('acceptedMissingTables'));
        $agentFeatureTableConditions = $this->findFeatureTableConditions();
        $boundSkillNames = $this->findBoundSkillNames();
        $requiredTableNamesByEntityClass = [];
        foreach (EntityModelGeneratorService::getAllEntityClasses(filterOverriddenEntities: true) as $entityClassWithNamespace) {
            $entityClassName = $entityClassWithNamespace->getNameWithNamespace();
            if (!$this->isEntityOfPackage($entityClassName, $packageSourceRoots)) {
                continue;
            }
            if ($agentFeatureTableConditions->findReasonTableIsNotRequired($entityClassName, $boundSkillNames) !== null) {
                continue;
            }
            $databaseModel = DatabaseModel::fromEntityClass($entityClassName);
            if ($databaseModel === null || $databaseModel->parentEntityCLassWithNamespace !== null || ($databaseModel->sqlTableName ?? '') === '') {
                continue;
            }
            $tableName = (string)$databaseModel->sqlTableName;
            if (EntityModelGeneratorService::isExcludedSqlTableName($tableName) || in_array(strtolower($tableName), $acceptedTableNames, true)) {
                continue;
            }
            $requiredTableNamesByEntityClass[$entityClassName] = $tableName;
        }
        ksort($requiredTableNamesByEntityClass);
        return $requiredTableNamesByEntityClass;
    }

    /** The conditions every provider declares; a provider that throws is skipped with a warning */
    protected function findFeatureTableConditions(): AgentFeatureTableConditions
    {
        $agentFeatureTableConditions = new AgentFeatureTableConditions();
        foreach ($this->agentFeatureTableConditionProviders as $agentFeatureTableConditionProvider) {
            try {
                foreach ($agentFeatureTableConditionProvider->getFeatureTableConditions()->getElements() as $agentFeatureTableCondition) {
                    $agentFeatureTableConditions->add($agentFeatureTableCondition);
                }
            } catch (Throwable $providerFailure) {
                DDDService::instance()->getLogger()->warning('[AgentInstallationCheck] feature table conditions of ' . $agentFeatureTableConditionProvider::class . ' skipped: ' . $providerFailure->getMessage());
            }
        }
        return $agentFeatureTableConditions;
    }

    /**
     * The skill names config/app/AI/skillPackages.php binds.
     *
     * @return string[]
     */
    protected function findBoundSkillNames(): array
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        return is_array($configuredBinding) ? array_map('strval', array_keys($configuredBinding)) : [];
    }

    /** Whether the class or one of its parent classes lies below one of the package source roots */
    protected function isEntityOfPackage(string $entityClassName, array $packageSourceRoots): bool
    {
        if (!class_exists($entityClassName)) {
            return false;
        }
        $reflectionClass = new ReflectionClass($entityClassName);
        while ($reflectionClass !== false) {
            $classFile = $reflectionClass->getFileName();
            if (is_string($classFile)) {
                foreach ($packageSourceRoots as $packageSourceRoot) {
                    if ($packageSourceRoot->containsFilePath($classFile)) {
                        return true;
                    }
                }
            }
            $reflectionClass = $reflectionClass->getParentClass();
        }
        return false;
    }

    /**
     * The table names of the default connection, without a schema prefix.
     *
     * @return string[]
     */
    protected function findLiveTableNames(): array
    {
        $tableNames = [];
        foreach (EntityManagerFactory::getInstance()->getConnection()->createSchemaManager()->listTableNames() as $qualifiedTableName) {
            $tableNameParts = explode('.', (string)$qualifiedTableName);
            $tableNames[] = trim((string)end($tableNameParts), '"`');
        }
        return $tableNames;
    }

    /**
     * `renamedConfigKeys`: a config key a package renamed (its `renames.json`, kind `configKey`) or removed
     * ({@see REMOVED_CONFIG_KEYS_BY_VERSION}) that the application still sets (WARNING).
     */
    protected function addRenamedConfigKeyFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        foreach ($this->findPackageRenames()->getElements() as $agentPackageRename) {
            if ($agentPackageRename->kind !== AgentPackageRename::KIND_CONFIG_KEY || !$this->isConfigKeySet($agentPackageRename->oldName)) {
                continue;
            }
            $agentInstallationFindings->addFinding(
                static::CHECK_RENAMED_CONFIG_KEYS,
                AgentInstallationFinding::SEVERITY_WARNING,
                $agentPackageRename->oldName,
                "The config key '{$agentPackageRename->oldName}' was renamed to '{$agentPackageRename->newName}' in {$agentPackageRename->packageName} {$agentPackageRename->version}; the package no longer reads the old key.",
                'Move the value to the new key (' . static::RENAMES_BIN . ' --apply renames it).'
            );
        }
        foreach (static::REMOVED_CONFIG_KEYS_BY_VERSION as $version => $removedConfigKeys) {
            foreach ($removedConfigKeys as $removedConfigKey) {
                if (!$this->isConfigKeySet($removedConfigKey)) {
                    continue;
                }
                $agentInstallationFindings->addFinding(
                    static::CHECK_RENAMED_CONFIG_KEYS,
                    AgentInstallationFinding::SEVERITY_WARNING,
                    $removedConfigKey,
                    "The config key '$removedConfigKey' belongs to a feature removed in $version; it is no longer read.",
                    'Remove it (see the CHANGELOG of that release).'
                );
            }
        }
    }

    protected function isConfigKeySet(string $configKey): bool
    {
        try {
            return Config::get($configKey) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * `consoleCommands`: a name (or alias) of a package command that the console of this run does not resolve (WARNING:
     * the cron line or the host script that runs it fails). Skipped without a console application.
     */
    protected function addConsoleCommandFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        if ($this->consoleApplication === null) {
            return;
        }
        foreach ($this->findPackageCommandNamesByCommandClass() as $commandClassName => $commandNames) {
            foreach ($commandNames as $commandName) {
                if ($this->consoleApplication->has($commandName)) {
                    continue;
                }
                $agentInstallationFindings->addFinding(
                    static::CHECK_CONSOLE_COMMANDS,
                    AgentInstallationFinding::SEVERITY_WARNING,
                    $commandName,
                    "The package command '$commandName' ($commandClassName) is not registered in this console: a cron line or script that runs it fails.",
                    'Clear the cache (cache:clear with the same APP_ENV), check that the module is registered (extra.ddd-module) and that no exclude of services.yaml removes the command.'
                );
            }
        }
    }

    /**
     * command class => its names and aliases, for every concrete command class with `#[AsCommand]` below the package
     * source roots' `Symfony/Commands`.
     *
     * @return array<string, string[]>
     */
    protected function findPackageCommandNamesByCommandClass(): array
    {
        $commandNamesByCommandClass = [];
        foreach ($this->findPackageSourceRoots() as $packageSourceRoot) {
            $commandsDirectory = $packageSourceRoot->directory . '/' . static::CONSOLE_COMMANDS_FOLDER;
            if (!is_dir($commandsDirectory)) {
                continue;
            }
            $fileIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($commandsDirectory, FilesystemIterator::SKIP_DOTS));
            foreach ($fileIterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $commandClassName = $packageSourceRoot->findClassNameForFilePath($file->getPathname());
                if ($commandClassName === null) {
                    continue;
                }
                $commandNames = $this->findCommandNamesOfClass($commandClassName);
                if ($commandNames !== []) {
                    $commandNamesByCommandClass[$commandClassName] = $commandNames;
                }
            }
        }
        ksort($commandNamesByCommandClass);
        return $commandNamesByCommandClass;
    }

    /**
     * The names and aliases a concrete class declares with `#[AsCommand]`; [] for anything else.
     *
     * @return string[]
     */
    protected function findCommandNamesOfClass(string $commandClassName): array
    {
        try {
            if (!class_exists($commandClassName)) {
                return [];
            }
            $reflectionClass = new ReflectionClass($commandClassName);
            if ($reflectionClass->isAbstract()) {
                return [];
            }
            $asCommandAttributes = $reflectionClass->getAttributes(AsCommand::class);
            if ($asCommandAttributes === []) {
                return [];
            }
            $asCommand = $asCommandAttributes[0]->newInstance();
        } catch (Throwable) {
            return [];
        }
        $commandNames = [];
        // the attribute joins name and aliases with '|' (a leading '|' marks a hidden command)
        foreach (explode('|', (string)$asCommand->name) as $commandName) {
            $commandName = trim((string)$commandName);
            if ($commandName !== '' && !in_array($commandName, $commandNames, true)) {
                $commandNames[] = $commandName;
            }
        }
        return $commandNames;
    }

    /**
     * `skillLint`: the skill-file lint and the naming lint over every skill root (WARNING; a skill file of the
     * application whose frontmatter does not parse or names no description: BLOCKING).
     */
    protected function addSkillLintFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        /** @var AISkillSourcesService $aISkillSourcesService */
        $aISkillSourcesService = DDDService::instance()->getService(AISkillSourcesService::class);
        /** @var AISkillFilesLintService $aISkillFilesLintService */
        $aISkillFilesLintService = DDDService::instance()->getService(AISkillFilesLintService::class);
        $skillRootDirectories = $aISkillSourcesService->getSkillRootDirectories();
        foreach ($aISkillFilesLintService->findSkillFileProblems($skillRootDirectories) as $skillFileProblem) {
            $agentInstallationFindings->addFinding(
                static::CHECK_SKILL_LINT,
                $this->isApplicationPath($skillFileProblem) ? AgentInstallationFinding::SEVERITY_BLOCKING : AgentInstallationFinding::SEVERITY_WARNING,
                $this->findSubjectOfLintProblem($skillFileProblem),
                $skillFileProblem,
                'Fix the skill file (the skills-and-purposes skill, "skill file format").'
            );
        }
        $namingProblems = [
            ...$aISkillFilesLintService->findSkillNamingProblems($skillRootDirectories),
            ...$aISkillFilesLintService->findBindingNamingProblems(),
        ];
        foreach ($namingProblems as $namingProblem) {
            $agentInstallationFindings->addFinding(
                static::CHECK_SKILL_LINT,
                AgentInstallationFinding::SEVERITY_WARNING,
                $this->findSubjectOfLintProblem($namingProblem),
                $namingProblem,
                'Rename (skill <Topic>.<Feature>, package id <provider>.<topic>.<feature>), or list the old name in ' . AISkillFilesLintService::CONFIG_LINT_ALLOW_LIST . '.'
            );
        }
    }

    /** The part of a lint line before its first `: ` (a file or `skill binding`) */
    protected function findSubjectOfLintProblem(string $lintProblem): string
    {
        $separatorPosition = strpos($lintProblem, ': ');
        return $separatorPosition === false ? $lintProblem : substr($lintProblem, 0, $separatorPosition);
    }

    /**
     * `pendingRenames`: the renames of an installed package newer than the version the application recorded in
     * `.ddd-renames.json` and not newer than the installed version (WARNING per package).
     */
    protected function addPendingRenameFindings(AgentInstallationFindings $agentInstallationFindings): void
    {
        $appliedThroughVersionByPackageName = $this->readAppliedThroughVersionByPackageName();
        $pendingRenamesCountByPackageName = [];
        $pendingVersionsByPackageName = [];
        foreach ($this->findPackageRenames()->getElements() as $agentPackageRename) {
            $appliedThroughVersion = $appliedThroughVersionByPackageName[$agentPackageRename->packageName] ?? null;
            if ($appliedThroughVersion !== null && version_compare($agentPackageRename->version, $appliedThroughVersion, '<=')) {
                continue;
            }
            if ($agentPackageRename->installedVersion !== null && version_compare($agentPackageRename->version, $agentPackageRename->installedVersion, '>')) {
                continue;
            }
            $pendingRenamesCountByPackageName[$agentPackageRename->packageName] = ($pendingRenamesCountByPackageName[$agentPackageRename->packageName] ?? 0) + 1;
            $pendingVersionsByPackageName[$agentPackageRename->packageName][$agentPackageRename->version] = true;
        }
        foreach ($pendingRenamesCountByPackageName as $packageName => $pendingRenamesCount) {
            $pendingVersions = array_keys($pendingVersionsByPackageName[$packageName]);
            usort($pendingVersions, 'version_compare');
            $hasRecord = isset($appliedThroughVersionByPackageName[$packageName]);
            $agentInstallationFindings->addFinding(
                static::CHECK_PENDING_RENAMES,
                AgentInstallationFinding::SEVERITY_WARNING,
                (string)$packageName,
                "$pendingRenamesCount rename(s) of $packageName are pending (" . implode(', ', $pendingVersions) . ')'
                . ($hasRecord ? '' : '; ' . static::RENAMES_STATE_FILE_NAME . ' records none for it') . '.',
                'Run ' . static::RENAMES_BIN . ' (dry run), then ' . static::RENAMES_BIN . ' --apply; a fresh installation without old names: ' . static::RENAMES_BIN . ' --mark-applied.'
            );
        }
    }

    /**
     * package name => the version up to which the application applied its renames (`.ddd-renames.json` in the
     * application's root); [] without the file.
     *
     * @return array<string, string>
     */
    protected function readAppliedThroughVersionByPackageName(): array
    {
        $stateFile = $this->findRenamesStateFile();
        $stateDocument = $stateFile !== null ? json_decode((string)file_get_contents($stateFile), true) : null;
        $recordedPackages = is_array($stateDocument['packages'] ?? null) ? $stateDocument['packages'] : [];
        $appliedThroughVersionByPackageName = [];
        foreach ($recordedPackages as $packageName => $recordedPackage) {
            if (is_array($recordedPackage) && is_string($recordedPackage['appliedThroughVersion'] ?? null)) {
                $appliedThroughVersionByPackageName[(string)$packageName] = $recordedPackage['appliedThroughVersion'];
            }
        }
        return $appliedThroughVersionByPackageName;
    }

    /**
     * The application's `.ddd-renames.json`: the path `renamesStateFile` of config/app/AI/installationCheck.php
     * (absolute, or relative to the project directory) when set; else the first one found in the project directory
     * and its parent directories, up to the repository root (the directory holding `.git`) — a project in a sub-folder
     * of its repository keeps the file at the repository root. Null when there is none.
     */
    public function findRenamesStateFile(): ?string
    {
        $projectDirectory = rtrim($this->getProjectDirectory(), '/');
        $configuredStateFileValue = Config::get(static::CONFIG_PATH . '.renamesStateFile');
        $configuredStateFile = is_string($configuredStateFileValue) ? trim($configuredStateFileValue) : '';
        if ($configuredStateFile !== '') {
            $stateFile = str_starts_with($configuredStateFile, '/') ? $configuredStateFile : $projectDirectory . '/' . $configuredStateFile;
            return is_file($stateFile) ? $stateFile : null;
        }
        $directory = $projectDirectory;
        while ($directory !== '' && $directory !== '/') {
            $stateFile = $directory . '/' . static::RENAMES_STATE_FILE_NAME;
            if (is_file($stateFile)) {
                return $stateFile;
            }
            if (file_exists($directory . '/.git')) {
                return null;
            }
            $parentDirectory = dirname($directory);
            if ($parentDirectory === $directory) {
                return null;
            }
            $directory = $parentDirectory;
        }
        return null;
    }

    /** The application's project directory (the kernel's) */
    protected function getProjectDirectory(): string
    {
        return DDDService::instance()->getRootDir();
    }

    /** Every rename the installed packages list in their `renames.json` (one per package root) */
    protected function findPackageRenames(): AgentPackageRenames
    {
        $agentPackageRenames = AgentPackageRenames::newInstance();
        $readPackageDirectories = [];
        foreach ($this->findPackageSourceRoots() as $packageSourceRoot) {
            $packageDirectory = dirname($packageSourceRoot->directory);
            if (isset($readPackageDirectories[$packageDirectory])) {
                continue;
            }
            $readPackageDirectories[$packageDirectory] = true;
            $agentPackageRenames->addRenamesOfPackageDirectory($packageDirectory, static::RENAMES_FILE_NAME);
        }
        return $agentPackageRenames;
    }

    /**
     * The source roots of the installed packages (every root but the application's).
     *
     * @return AgentSourceRoot[]
     */
    protected function findPackageSourceRoots(): array
    {
        /** @var AgentSourceRootsService $agentSourceRootsService */
        $agentSourceRootsService = DDDService::instance()->getService(AgentSourceRootsService::class);
        $packageSourceRoots = [];
        foreach ($agentSourceRootsService->getSourceRoots()->getElements() as $agentSourceRoot) {
            if (!$agentSourceRoot->isApplication) {
                $packageSourceRoots[] = $agentSourceRoot;
            }
        }
        return $packageSourceRoots;
    }

    /** Whether a path (or a lint line starting with one) lies in the application, outside its `vendor/` */
    protected function isApplicationPath(string $path): bool
    {
        $applicationRootDirectory = realpath(DDDService::instance()->getRootDir());
        if ($applicationRootDirectory === false) {
            return false;
        }
        return str_starts_with($path, $applicationRootDirectory . '/') && !str_starts_with($path, $applicationRootDirectory . '/vendor/');
    }

    /**
     * A list of names of {@see CONFIG_PATH} (`skippedChecks`, `acceptedUnboundSkills`, `acceptedUnmappedRights`,
     * `acceptedSkillRightGaps`, `acceptedMissingTables`); [] when unset.
     *
     * @return string[]
     */
    protected function readConfiguredNames(string $configKey): array
    {
        $configuredNames = Config::get(static::CONFIG_PATH . '.' . $configKey);
        return is_array($configuredNames) ? array_values(array_map('strval', array_filter($configuredNames, 'is_string'))) : [];
    }
}
