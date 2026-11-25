<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Skills;

use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkillFrontmatterAndBody;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Domain\AI\Entities\Skills\RequestedAISkills;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\Service;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads AISkills from their `.md` config — stringently mirrors AIPromptsService.
 *
 * `getAISkillByName('Agent.Mandates')` → `Config::get('AI.Skills.Agent.Mandates')` reads
 * `config/app/AI/Skills/Assistant/Mandates.md` of the first config root holding it (the application's before a module's,
 * {@see AISkillSourcesService}); the skill name is the dotted path itself, YAML frontmatter →
 * {description} (the catalog selection signal), body → load-time instruction.
 */
class AISkillsService extends Service
{
    /** @var string Config path prefix for the skills directory (mirrors AIPromptsService::CONFIG_PROMPTS_PREFIX) */
    public const string CONFIG_SKILLS_PREFIX = 'AI.Skills.';

    /** @var string Config path of the skill → MCP-package binding (`config/app/AI/skillPackages.php`) */
    public const string CONFIG_SKILL_PACKAGES = 'AI.skillPackages';

    /**
     * @var string Config path of the skill REQUIREMENT OVERLAY (`config/app/AI/skillRequirements.php`):
     *      skill name => {requiredFeatureFlags?: string[], anyOfRequiredFeatureFlags?: string[],
     *      requiredAccountRights?: string[], requiresAccountBillingAccess?: bool}. The gates an application puts on a
     *      skill without editing the skill file (a module's skill knows no application flag or right); merged into the
     *      frontmatter at load time ({@see applySkillRequirementOverlay()}).
     */
    public const string CONFIG_SKILL_REQUIREMENTS = 'AI.skillRequirements';

    /** @var string Config path of the persona the skill and purpose texts name (`config/app/AI/agentPersona.php`). */
    public const string CONFIG_AGENT_PERSONA = 'AI.agentPersona';

    /** @var string Placeholder in a skill or purpose file for the agent's name ({@see substitutePersonaPlaceholders()}). */
    public const string PLACEHOLDER_AGENT_NAME = '{%AGENT_NAME%}';

    /** @var string Placeholder in a skill or purpose file for the product's name ({@see substitutePersonaPlaceholders()}). */
    public const string PLACEHOLDER_PRODUCT_NAME = '{%PRODUCT_NAME%}';

    /** @var string The agent's name when the application configures none. */
    public const string DEFAULT_AGENT_NAME = 'Assistant';

    /** @var string The product's name when the application configures none. */
    public const string DEFAULT_PRODUCT_NAME = 'this product';

    /**
     * @var string[] The frontmatter keys a skill file may carry — exactly the keys {@see getAISkillByName()} reads; the
     *      skill-file lint rejects any other key (a misspelt gate is silently no gate).
     */
    public const array SKILL_FRONTMATTER_KEYS = [
        'description', 'shortDescription', 'decisionCriteria', 'salesTriggers', 'nonEvictable', 'requiredFeatureFlags',
        'anyOfRequiredFeatureFlags', 'requiresVisionCapabilities', 'requiredAccountRights', 'requiresAccountBillingAccess',
        'isSubSkill', 'subSkillNames', 'onlyUnderPurposeNames', 'notUnderPurposeNames', 'preloadOnWidgetTypes',
        'providesWidgetTypes', 'onlyOnInitiatorTypes', 'turnMinimumSteps', 'subSkillOf',
    ];

    /**
     * @var array<string, array<string, string[]>> per binding (a hash over the registered skill names): root skill
     *      name => the registered skills that name it in `subSkillOf`; built once per binding and process
     */
    protected static array $subSkillNamesOfRootByBindingSignature = [];

    /** @var array<string, array<string, mixed>>|null the skill requirement overlay, read once per process */
    protected static ?array $skillRequirementsBySkillName = null;

    /** @var bool When true, a missing/invalid skill throws instead of returning null */
    public bool $throwErrors = false;

    /**
     * A `load_skills` request resolved once: the requested names in request order and every requested
     * and already-loaded name resolved to its skill (an unknown name resolves to none) — what the pure load gates read.
     *
     * @param string[] $requestedSkillNames
     * @param string[] $loadedSkillNames
     */
    public function resolveRequestedSkills(array $requestedSkillNames, array $loadedSkillNames): RequestedAISkills
    {
        $requestedAISkills = new RequestedAISkills();
        $requestedAISkills->requestedSkillNames = array_values(array_map('strval', $requestedSkillNames));
        $requestedAISkills->loadedSkillNames = array_values(array_map('strval', $loadedSkillNames));
        foreach (array_unique(array_merge($requestedAISkills->requestedSkillNames, $requestedAISkills->loadedSkillNames)) as $skillName) {
            $aiSkill = $this->getAISkillByName($skillName);
            if ($aiSkill !== null) {
                $requestedAISkills->addResolvedSkill($aiSkill);
            }
        }
        return $requestedAISkills;
    }

    /**
     * The catalog name of the skill `$givenSkillName` names: the name itself when it is a skill, else the ONE skill whose
     * name equals it once the dots and the case of both are ignored (a model wrote a dot too many). Null when no skill or
     * more than one matches.
     */
    public function resolveSkillNameIgnoringDotsAndCase(string $givenSkillName): ?string
    {
        $allSkillNames = $this->getAllSkillNames();
        if (in_array($givenSkillName, $allSkillNames, true)) {
            return $givenSkillName;
        }
        $comparableGivenSkillName = static::buildSkillNameComparableWithoutDotsAndCase($givenSkillName);
        if ($comparableGivenSkillName === '') {
            return null;
        }
        $matchingSkillNames = [];
        foreach ($allSkillNames as $skillName) {
            if (static::buildSkillNameComparableWithoutDotsAndCase((string)$skillName) === $comparableGivenSkillName) {
                $matchingSkillNames[] = (string)$skillName;
            }
        }
        return count($matchingSkillNames) === 1 ? $matchingSkillNames[0] : null;
    }

    /**
     * The names of `$givenSkillNames` that name no skill, not even with their dots and their case ignored
     * ({@see resolveSkillNameIgnoringDotsAndCase()}).
     *
     * @param array<int, mixed> $givenSkillNames
     * @return string[]
     */
    public function findSkillNamesThatAreNoSkill(array $givenSkillNames): array
    {
        $skillNamesThatAreNoSkill = [];
        foreach ($givenSkillNames as $givenSkillName) {
            if (!is_string($givenSkillName) || $this->resolveSkillNameIgnoringDotsAndCase($givenSkillName) === null) {
                $skillNamesThatAreNoSkill[] = is_scalar($givenSkillName) ? (string)$givenSkillName : '';
            }
        }
        return $skillNamesThatAreNoSkill;
    }

    /**
     * The dotted names of every registered skill ({@see AISkill::getAllSkillNames()}).
     *
     * @return string[]
     */
    public function getAllSkillNames(): array
    {
        return array_values(array_map('strval', AISkill::getAllSkillNames()));
    }

    /** `$skillName` without its dots and spaces at the ends, in lower case: the form two spellings of a name compare in. */
    public static function buildSkillNameComparableWithoutDotsAndCase(string $skillName): string
    {
        return mb_strtolower(str_replace('.', '', trim($skillName)));
    }

    /**
     * The resolved skills of a loaded capability set's SKILL names, in load order, an unknown name skipped
     * (the set no longer resolves itself). Remembered on the set, so the carried set shared by several rows is
     * resolved once.
     */
    public function findLoadedSkillsOf(LoadedCapabilities $loadedCapabilities): AISkills
    {
        $rememberedLoadedSkills = $loadedCapabilities->getRememberedLoadedSkills();
        if ($rememberedLoadedSkills !== null) {
            return $rememberedLoadedSkills;
        }
        $loadedSkills = new AISkills();
        foreach ($loadedCapabilities->getLoadedSkillNames() as $loadedSkillName) {
            $loadedSkill = $this->getAISkillByName($loadedSkillName);
            if ($loadedSkill !== null) {
                $loadedSkills->add($loadedSkill);
            }
        }
        $loadedCapabilities->rememberLoadedSkills($loadedSkills);
        return $loadedSkills;
    }

    /**
     * Returns an AISkill by its dotted name, loaded from its `.md` config via Config::get().
     *
     * @param string $skillName e.g. 'Agent.Mandates'
     * @return AISkill|null
     * @throws NotFoundException
     */
    public function getAISkillByName(string $skillName): ?AISkill
    {
        $raw = $this->findRawSkillFile($skillName);
        if (!is_string($raw) || $raw === '') {
            if ($this->throwErrors) {
                throw new NotFoundException("AISkill not found: $skillName");
            }
            return null;
        }

        $skillFrontmatterAndBody = self::splitFrontmatter($this->substitutePersonaPlaceholders($raw));
        $frontmatter = $skillFrontmatterAndBody->frontmatter;
        $body = $skillFrontmatterAndBody->body;
        $frontmatter = $this->applySkillRequirementOverlay($skillName, $frontmatter);

        $skill = AISkill::newInstance();
        $skill->name = $skillName; // the dotted name is the identifier (= registry key / file path)
        $skill->description = (string)($frontmatter['description'] ?? '');
        $skill->shortDescription = (string)($frontmatter['shortDescription'] ?? ''); // awareness entry's one-liner
        $skill->decisionCriteria = trim((string)($frontmatter['decisionCriteria'] ?? '')); // the decision model's situations line
        $skill->salesTriggers = (string)($frontmatter['salesTriggers'] ?? ''); // "offer when" cues of a locked entry
        $skill->instruction = trim($body);
        $skill->nonEvictable = (bool)($frontmatter['nonEvictable'] ?? false); // PIN — frontmatter opt-in, default false
        // Step floor of a turn while the skill is loaded (`turnMinimumSteps: 30`): long jobs (build, render, check,
        // hand over) need more steps than one plain turn; capped by the planned-turn ceiling (AIConversationTurnBudget).
        $skill->turnMinimumSteps = AISkill::readTurnMinimumStepsOfFrontmatter($frontmatter['turnMinimumSteps'] ?? null);
        // Product-flag gate: the skill is offered only if the agent space has ALL these flags available. Empty =
        // ungated. Frontmatter list of FeatureFlag constant VALUES, e.g. `requiredFeatureFlags: ['WORKSHOP.BOOKINGS']`.
        $skill->requiredFeatureFlags = array_values(array_map('strval', (array)($frontmatter['requiredFeatureFlags'] ?? [])));
        // Any-of gate (the skill twin of #[MCPRequiresAnyFeatureFlag]): on top of ALL required flags, AT LEAST ONE of
        // these, e.g. `anyOfRequiredFeatureFlags: [AI.AGENT.CHANNEL.APPVOICE, AI.AGENT.CHANNEL.PHONE]`; the first is the upgrade offer.
        $skill->anyOfRequiredFeatureFlags = array_values(array_map('strval', (array)($frontmatter['anyOfRequiredFeatureFlags'] ?? [])));
        // Vision gate: the skill's work needs the model to SEE images (e.g. a browser skill reading
        // screenshots). load_skills refuses it on a blind model; re-checked on a model switch + at delegation spawn.
        $skill->requiresVisionCapabilities = (bool)($frontmatter['requiresVisionCapabilities'] ?? false);
        // Account-rights gate: the SECOND axis — the flags ask what the plan of the space includes, these ask
        // whether the acting USER may use it. Right names of the application's account rights, e.g.
        // `requiredAccountRights: ['manageBookings']`. Offer-side only; the same rights must also sit on the skill's
        // packages as #[MCPRequiresAccountRights] (a test of the application enforces it).
        $skill->requiredAccountRights = array_values(array_map('strval', (array)($frontmatter['requiredAccountRights'] ?? [])));
        // Billing-surface gate: invoices / payment / invoice address exist only where the host bills the
        // customer directly AND the acting user may see billing. Neither purchasable nor grantable for a reseller
        // account — the capability simply is not there, so the agent must not mention it either.
        $skill->requiresAccountBillingAccess = (bool)($frontmatter['requiresAccountBillingAccess'] ?? false);
        // Sub-skill mechanics: `isSubSkill: true` = not in the general catalog,
        // offered only while a ROOT listing it in `subSkillNames` is loaded. Definition lives at AISkill fields.
        // `subSkillOf: 'Agent.Web.Search'` = the skill names its root itself: a sub-skill, attached to every named root
        // that is registered — the root's own `subSkillNames` and the sub-skills naming it are united.
        $skill->subSkillOf = AISkill::readSubSkillOfFrontmatter($frontmatter['subSkillOf'] ?? null);
        $skill->isSubSkill = $skill->subSkillOf !== [] || (bool)($frontmatter['isSubSkill'] ?? false);
        $skill->subSkillNames = array_values(array_unique([
            ...array_map('strval', (array)($frontmatter['subSkillNames'] ?? [])),
            ...$this->findSubSkillNamesNamingRoot($skillName),
        ]));
        // Purpose binding: `onlyUnderPurposeNames: ['Schedule.Run']` = offered/loadable only inside that purposed run.
        $skill->onlyUnderPurposeNames = array_values(array_map('strval', (array)($frontmatter['onlyUnderPurposeNames'] ?? [])));
        // Purpose exclusion: `notUnderPurposeNames: ['Schedule.Run']` = never offered/loadable/preloaded inside that purposed run.
        $skill->notUnderPurposeNames = array_values(array_map('strval', (array)($frontmatter['notUnderPurposeNames'] ?? [])));
        // Widget-triggered preload: `preloadOnWidgetTypes: ['BookingSelection']` = a customer message carrying that
        // fence loads the skill for the turn (see AISkill::$preloadOnWidgetTypes).
        $skill->preloadOnWidgetTypes = array_values(array_map('strval', (array)($frontmatter['preloadOnWidgetTypes'] ?? [])));
        // Widget catalog: `providesWidgetTypes: ['BookingsList']` = the widgets this skill teaches.
        $skill->providesWidgetTypes = array_values(array_map('strval', (array)($frontmatter['providesWidgetTypes'] ?? [])));
        // Initiator binding: `onlyOnInitiatorTypes: ['PHONE_CALL']` = offered, loadable and preloaded only in
        // a conversation a call started (see AISkill::$onlyOnInitiatorTypes).
        $skill->onlyOnInitiatorTypes = array_values(array_map('strval', (array)($frontmatter['onlyOnInitiatorTypes'] ?? [])));

        return $skill;
    }

    /**
     * The full skill catalog as {@see AISkills} — every registered skill loaded as an {@see AISkill} object (name +
     * description + instruction). The selection signal the model reads (via the load_skills / delegate tool
     * enums) to choose which skill(s) to load.
     */
    /**
     * The VERSION of the decision criteria (`criteriaVersion`): a short hash over every catalog skill's
     * name + criteria line, stamped on each decision event so a criteria edit is visible in the statistics.
     */
    public function getDecisionCriteriaVersion(): string
    {
        $lines = [];
        foreach ($this->getCatalog()->getElements() as $aiSkill) {
            $lines[(string) $aiSkill->name] = $aiSkill->getDecisionCriteria();
        }
        ksort($lines);
        return substr(sha1((string) json_encode($lines)), 0, 12);
    }

    public function getCatalog(): AISkills
    {
        $catalog = new AISkills();
        foreach (AISkill::getAllSkillNames() as $skillName) {
            $skill = $this->getAISkillByName($skillName);
            if ($skill !== null) {
                $catalog->add($skill);
            }
        }
        return $catalog;
    }

    /**
     * The raw text of a skill file by its dotted name (frontmatter and body, before any substitution); null or '' =
     * no such file.
     */
    public function findRawSkillFile(string $skillName): mixed
    {
        return Config::get(self::CONFIG_SKILLS_PREFIX . $skillName);
    }

    /**
     * The registered skills whose file names `$rootSkillName` in `subSkillOf` — attached only when the root is
     * registered too; [] for an unregistered root or without a binding.
     *
     * @return string[]
     */
    public function findSubSkillNamesNamingRoot(string $rootSkillName): array
    {
        return $this->getSubSkillNamesByRootName()[$rootSkillName] ?? [];
    }

    /**
     * Root skill name => the registered skills that name it in `subSkillOf`, both registered in the binding. Read from
     * the raw frontmatter of the registered skill files (with the requirement overlay), never through
     * {@see getAISkillByName()} (which asks this map); memoized per binding.
     *
     * @return array<string, string[]>
     */
    public function getSubSkillNamesByRootName(): array
    {
        try {
            $registeredSkillNames = AISkill::getAllSkillNames();
        } catch (\LogicException) {
            return [];
        }
        $bindingSignature = md5(implode('|', $registeredSkillNames));
        if (isset(self::$subSkillNamesOfRootByBindingSignature[$bindingSignature])) {
            return self::$subSkillNamesOfRootByBindingSignature[$bindingSignature];
        }
        $subSkillNamesByRootName = [];
        foreach ($registeredSkillNames as $registeredSkillName) {
            foreach ($this->findSubSkillOfRootNamesOfSkillFile($registeredSkillName) as $rootSkillName) {
                if ($rootSkillName !== $registeredSkillName && in_array($rootSkillName, $registeredSkillNames, true)) {
                    $subSkillNamesByRootName[$rootSkillName][] = $registeredSkillName;
                }
            }
        }
        self::$subSkillNamesOfRootByBindingSignature[$bindingSignature] = $subSkillNamesByRootName;
        return $subSkillNamesByRootName;
    }

    /**
     * The roots a skill file names in `subSkillOf` (after the requirement overlay); [] without a file.
     *
     * @return string[]
     */
    public function findSubSkillOfRootNamesOfSkillFile(string $skillName): array
    {
        $raw = $this->findRawSkillFile($skillName);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $frontmatter = self::splitFrontmatter($this->substitutePersonaPlaceholders($raw))->frontmatter;
        $frontmatter = $this->applySkillRequirementOverlay($skillName, $frontmatter);
        return AISkill::readSubSkillOfFrontmatter($frontmatter['subSkillOf'] ?? null);
    }

    /** @var string[]|null Memoized union of every gate-referenced flag name (deploy-static). */
    protected static ?array $agentRelevantFeatureFlagNames = null;

    /**
     * The AGENT-RELEVANT feature flags: the union of every flag referenced by a gate — each skill's frontmatter
     * `requiredFeatureFlags` and `anyOfRequiredFeatureFlags` PLUS every tool's / package's #[MCPRequiresFeatureFlags] and
     * #[MCPRequiresAnyFeatureFlag]. These are exactly the ~30 flags
     * that gate a capability (the rest of the ~200 flags are NAV/display noise and not agent-relevant).
     * The product-awareness render uses this as the "which flags matter" set. Deploy-static → memoized (the scan reflects
     * every package once per worker).
     *
     * @return string[]
     */
    public function getAgentRelevantFeatureFlagNames(): array
    {
        if (self::$agentRelevantFeatureFlagNames !== null) {
            return self::$agentRelevantFeatureFlagNames;
        }
        $flagNameSet = [];
        foreach ($this->getCatalog()->getElements() as $aiSkill) {
            foreach (array_merge($aiSkill->requiredFeatureFlags, $aiSkill->anyOfRequiredFeatureFlags) as $featureFlagName) {
                $flagNameSet[$featureFlagName] = true;
            }
            foreach (ToolDefinitions::forPackages($aiSkill->getPackages())->getElements() as $toolDefinition) {
                foreach (array_merge($toolDefinition->requiredFeatureFlagNames, $toolDefinition->anyOfRequiredFeatureFlagNames) as $featureFlagName) {
                    $flagNameSet[$featureFlagName] = true;
                }
            }
        }
        return self::$agentRelevantFeatureFlagNames = array_keys($flagNameSet);
    }

    /**
     * The one substitution step of skill and purpose files: `{%AGENT_NAME%}` and `{%PRODUCT_NAME%}` are
     * replaced by the application's persona (`AI.agentPersona`: `agentName`, `productName`; defaults "Assistant" /
     * "this product"), so a module's files name no agent or product. Applied to the whole raw file before the
     * frontmatter is parsed (a description may name the agent too). A file without a placeholder is returned unchanged.
     */
    public function substitutePersonaPlaceholders(string $rawText): string
    {
        if (!str_contains($rawText, '{%')) {
            return $rawText;
        }
        $agentPersona = $this->getAgentPersona();
        $agentName = trim((string)($agentPersona['agentName'] ?? ''));
        $productName = trim((string)($agentPersona['productName'] ?? ''));
        return str_replace(
            [self::PLACEHOLDER_AGENT_NAME, self::PLACEHOLDER_PRODUCT_NAME],
            [$agentName !== '' ? $agentName : self::DEFAULT_AGENT_NAME, $productName !== '' ? $productName : self::DEFAULT_PRODUCT_NAME],
            $rawText
        );
    }

    /**
     * The persona the skill and purpose texts name (`AI.agentPersona`: `agentName`, `productName`); empty when no config
     * root ships the file.
     *
     * @return array<string, mixed>
     */
    public function getAgentPersona(): array
    {
        $agentPersona = Config::get(self::CONFIG_AGENT_PERSONA);
        return is_array($agentPersona) ? $agentPersona : [];
    }

    /**
     * The frontmatter of a skill with the application's requirement overlay merged in: each list gate of
     * the overlay is UNITED with the frontmatter's (the frontmatter's values first, the overlay's appended, duplicates
     * dropped — the first flag stays the upgrade offer), `requiresAccountBillingAccess` is true when either says so.
     * A skill without an overlay entry keeps its frontmatter unchanged.
     *
     * @param array<string, mixed> $frontmatter
     * @return array<string, mixed>
     */
    public function applySkillRequirementOverlay(string $skillName, array $frontmatter): array
    {
        $skillRequirements = $this->getSkillRequirementsBySkillName()[$skillName] ?? null;
        if (!is_array($skillRequirements) || $skillRequirements === []) {
            return $frontmatter;
        }
        return self::mergeRequirementOverlay($frontmatter, $skillRequirements, ['requiredFeatureFlags', 'anyOfRequiredFeatureFlags', 'requiredAccountRights'], ['requiresAccountBillingAccess']);
    }

    /**
     * A frontmatter with a requirement overlay merged in: every list key of the overlay united with the frontmatter's
     * list (frontmatter order first, then the overlay's new values), every bool key OR-ed. Keys the caller does not
     * name are ignored. Shared by the skill and the purpose overlays.
     *
     * @param array<string, mixed> $frontmatter
     * @param array<string, mixed> $requirementOverlay
     * @param string[] $listKeys
     * @param string[] $boolKeys
     * @return array<string, mixed>
     */
    public static function mergeRequirementOverlay(array $frontmatter, array $requirementOverlay, array $listKeys, array $boolKeys): array
    {
        foreach ($listKeys as $listKey) {
            if (!array_key_exists($listKey, $requirementOverlay)) {
                continue;
            }
            $frontmatterValues = array_values(array_map('strval', (array)($frontmatter[$listKey] ?? [])));
            $overlayValues = array_values(array_map('strval', (array)$requirementOverlay[$listKey]));
            $frontmatter[$listKey] = array_values(array_unique([...$frontmatterValues, ...$overlayValues]));
        }
        foreach ($boolKeys as $boolKey) {
            if (!array_key_exists($boolKey, $requirementOverlay)) {
                continue;
            }
            $frontmatter[$boolKey] = (bool)($frontmatter[$boolKey] ?? false) || (bool)$requirementOverlay[$boolKey];
        }
        return $frontmatter;
    }

    /**
     * The skill requirement overlay (`AI.skillRequirements`), read once per process — the application's file replaces
     * a module's file whole; no file = no overlay.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSkillRequirementsBySkillName(): array
    {
        if (self::$skillRequirementsBySkillName === null) {
            $configuredSkillRequirements = Config::get(self::CONFIG_SKILL_REQUIREMENTS);
            self::$skillRequirementsBySkillName = is_array($configuredSkillRequirements) ? $configuredSkillRequirements : [];
        }
        return self::$skillRequirementsBySkillName;
    }

    /**
     * Splits a `.md` config into its frontmatter fields and its body.
     * Frontmatter is a leading `---` … `---` YAML block; absent → empty frontmatter, whole input is body.
     *
     * @param string $raw
     * @return AISkillFrontmatterAndBody
     */
    public static function splitFrontmatter(string $raw): AISkillFrontmatterAndBody
    {
        if (preg_match('/^\x{FEFF}?\s*---\s*\R(.*?)\R---\s*\R?(.*)$/su', $raw, $matches)) {
            $parsed = Yaml::parse($matches[1]);
            return AISkillFrontmatterAndBody::forFrontmatterAndBody(is_array($parsed) ? $parsed : [], $matches[2]);
        }
        return AISkillFrontmatterAndBody::forFrontmatterAndBody([], $raw);
    }
}
