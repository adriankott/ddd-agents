<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\ToolCallComparisons;

use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * What the model and an outside client read of one package, dumped to a file and compared byte for byte:
 * - every tool's wire schema in the loop — bound to an agent space (its id argument optional), without one, and bound
 *   to an agent space for an owner on the phone line (the `[surface=…]` sections render differently) — exactly as
 *   {@see \DDD\Domain\AI\Entities\MCPPackages\ToolDefinition::toOpenAiToolSchema()} emits it; and the HTTP `tools/list`
 *   documentation of the package ({@see MCPPackageDocumentation}, uncached) with the bundles that list it — a package
 *   no bundle lists has no HTTP shape and is compared through the loop schema alone;
 * - every tool's `#[MCPToolSummary]` (whole; the decision reads its first
 *   {@see AISkill::MAX_DECISION_OWN_TOOL_SUMMARY_CHARS} characters) and, per skill that loads the package, the skill's
 *   tool list;
 * - the declarations the loop reads off the tool definition: the feature-flag and right gates, the effect (READ /
 *   WRITE / DELETE with its repeat and reuse flags), always-load, non-evictable, parallel-safe, state refresh, untrusted
 *   content, the execution ceiling and the image budget.
 * Built from reflection alone, BEFORE the first tool runs: a tool that hides `objectType` for its own output changes
 * how every later object serializes in the process. Pure.
 *
 * The stored key of every section and tool field comes from {@see getStoredKeyByField()}: an application whose dumps
 * carry other key names maps them there, so a dump it made before compares byte for byte.
 *
 * @phpstan-consistent-constructor
 */
class ToolCallComparisonDefinitionsDump extends ValueObject
{
    /** @var string[] The top-level sections of the dump, in their stored order */
    public const array STORED_SECTIONS_IN_ORDER = ['package', 'tools', 'httpBundles', 'httpDocumentation', 'toolNamesBySkill'];

    /** @var string[] The fields of one tool, in their stored order */
    public const array STORED_TOOL_FIELDS_IN_ORDER = [
        'loopSchemaAgentSpaceBound',
        'loopSchemaAgentSpaceLess',
        'loopSchemaAgentSpaceBoundPhoneLine',
        'summary',
        'requiredFeatureFlagNames',
        'anyOfRequiredFeatureFlagNames',
        'requiredAccountRightNames',
        'effect',
        'isAlwaysLoad',
        'isNonEvictable',
        'isParallelSafe',
        'refreshesStateOnEveryCall',
        'returnsUntrustedContent',
        'executionTimeoutSeconds',
        'imageBudget',
    ];

    /** @var array The dump, keyed so that a byte difference can be traced to a tool and a section (the stored document) */
    protected array $sections = [];

    /**
     * @param string $packageClass the package's class name
     * @param string[] $bundleClasses the class names of every bundle of the application (the ones listing the package are named)
     * @param string[] $descriptionPrefixesOfToolArgumentsFilledFromAgentSpace by argument name: what the loop puts in
     *        front of an argument it fills in a conversation with an agent space, as the agent-spaces service names
     *        them — the agent-space-bound schemas are built with it
     */
    public static function buildForPackage(string $packageClass, array $bundleClasses, array $descriptionPrefixesOfToolArgumentsFilledFromAgentSpace): static
    {
        $toolCallComparisonDefinitionsDump = static::newInstance();
        $toolFieldsByName = [];
        foreach (ToolDefinitions::forPackages([$packageClass])->getElements() as $toolDefinition) {
            $toolFieldValues = [
                'loopSchemaAgentSpaceBound' => $toolDefinition->toOpenAiToolSchema($descriptionPrefixesOfToolArgumentsFilledFromAgentSpace),
                'loopSchemaAgentSpaceLess' => $toolDefinition->toOpenAiToolSchema(),
                'loopSchemaAgentSpaceBoundPhoneLine' => $toolDefinition->toOpenAiToolSchema($descriptionPrefixesOfToolArgumentsFilledFromAgentSpace, ownerIsOnThePhoneLine: true),
                'summary' => $toolDefinition->summary,
                'requiredFeatureFlagNames' => $toolDefinition->requiredFeatureFlagNames,
                'anyOfRequiredFeatureFlagNames' => $toolDefinition->anyOfRequiredFeatureFlagNames,
                'requiredAccountRightNames' => $toolDefinition->requiredAccountRightNames,
                'effect' => $toolDefinition->effect !== null ? [
                    'effect' => $toolDefinition->effect->effect,
                    'identicalRepeatDoesNewWork' => $toolDefinition->effect->identicalRepeatDoesNewWork,
                    'resultIsReusedWithinTurn' => $toolDefinition->effect->resultIsReusedWithinTurn,
                ] : null,
                'isAlwaysLoad' => $toolDefinition->isAlwaysLoad,
                'isNonEvictable' => $toolDefinition->isNonEvictable,
                'isParallelSafe' => $toolDefinition->isParallelSafe,
                'refreshesStateOnEveryCall' => $toolDefinition->refreshesStateOnEveryCall,
                'returnsUntrustedContent' => $toolDefinition->returnsUntrustedContent,
                'executionTimeoutSeconds' => $toolDefinition->executionTimeoutSeconds,
                'imageBudget' => $toolDefinition->imageBudget,
            ];
            $toolFieldsByName[$toolDefinition->name] = $toolCallComparisonDefinitionsDump->buildStoredFields(static::STORED_TOOL_FIELDS_IN_ORDER, $toolFieldValues);
        }
        ksort($toolFieldsByName);
        $bundleClassesListingThePackage = [];
        foreach ($bundleClasses as $bundleClass) {
            if (in_array($packageClass, $bundleClass::INCLUDED_PACKAGES, true)) {
                $bundleClassesListingThePackage[] = $bundleClass;
            }
        }
        sort($bundleClassesListingThePackage);
        $toolNamesBySkill = [];
        foreach (AISkill::getAllSkillNames() as $skillName) {
            if (in_array($packageClass, AISkill::getPackagesForSkill($skillName), true)) {
                $toolNamesBySkill[$skillName] = AISkill::getToolNamesForSkillName($skillName);
            }
        }
        ksort($toolNamesBySkill);
        $toolCallComparisonDefinitionsDump->sections = $toolCallComparisonDefinitionsDump->buildStoredFields(static::STORED_SECTIONS_IN_ORDER, [
            'package' => $packageClass,
            'tools' => $toolFieldsByName,
            'httpBundles' => $bundleClassesListingThePackage,
            'httpDocumentation' => $bundleClassesListingThePackage !== []
                ? json_decode((string)MCPPackageDocumentation::createFromClassName($packageClass)->toJSON(), true)
                : null,
            'toolNamesBySkill' => $toolNamesBySkill,
        ]);
        return $toolCallComparisonDefinitionsDump;
    }

    /** The dump as the file holds it (stable key order, pretty-printed, so a difference shows as a line diff too). */
    public function buildStoredJson(): string
    {
        return (string)json_encode($this->sections, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * The key each section and tool field is stored under, field → key. The default stores every field under its own
     * name; an application maps a field to the key its dumps carry.
     *
     * @return array<string, string>
     */
    public function getStoredKeyByField(): array
    {
        $fieldNames = array_merge(static::STORED_SECTIONS_IN_ORDER, static::STORED_TOOL_FIELDS_IN_ORDER);
        return array_combine($fieldNames, $fieldNames);
    }

    /** The key `$fieldName` is stored under. */
    public function findStoredKey(string $fieldName): string
    {
        return $this->getStoredKeyByField()[$fieldName] ?? $fieldName;
    }

    /**
     * Where a replayed dump differs from the recorded one, one line per tool section (or top-level section); empty when
     * the two files are byte-identical.
     *
     * @return string[]
     */
    public function buildDifferences(string $recordedDumpJson, string $replayedDumpJson): array
    {
        if ($recordedDumpJson === $replayedDumpJson) {
            return [];
        }
        $recordedSections = (array)json_decode($recordedDumpJson, true);
        $replayedSections = (array)json_decode($replayedDumpJson, true);
        $toolsKey = $this->findStoredKey('tools');
        $differences = [];
        foreach (array_unique(array_merge(array_keys($recordedSections), array_keys($replayedSections))) as $sectionName) {
            if ($sectionName === $toolsKey) {
                continue;
            }
            if (json_encode($recordedSections[$sectionName] ?? null) !== json_encode($replayedSections[$sectionName] ?? null)) {
                $differences[] = "definitions: section {$sectionName} differs";
            }
        }
        $recordedTools = (array)($recordedSections[$toolsKey] ?? []);
        $replayedTools = (array)($replayedSections[$toolsKey] ?? []);
        foreach (array_unique(array_merge(array_keys($recordedTools), array_keys($replayedTools))) as $toolName) {
            if (!isset($recordedTools[$toolName]) || !isset($replayedTools[$toolName])) {
                $differences[] = "definitions: tool {$toolName} exists in one dump only";
                continue;
            }
            foreach (array_unique(array_merge(array_keys((array)$recordedTools[$toolName]), array_keys((array)$replayedTools[$toolName]))) as $toolSectionName) {
                if (json_encode($recordedTools[$toolName][$toolSectionName] ?? null) !== json_encode($replayedTools[$toolName][$toolSectionName] ?? null)) {
                    $differences[] = "definitions: {$toolName}.{$toolSectionName} differs";
                }
            }
        }
        return $differences !== [] ? $differences : ['definitions: the files differ in their bytes (formatting or key order)'];
    }

    /**
     * The values of `$fieldNames`, in that order, under their stored keys.
     *
     * @param string[] $fieldNames
     * @param array $fieldValues field → value
     * @return array the stored document of one section or tool
     */
    protected function buildStoredFields(array $fieldNames, array $fieldValues): array
    {
        $storedFields = [];
        foreach ($fieldNames as $fieldName) {
            $storedFields[$this->findStoredKey($fieldName)] = $fieldValues[$fieldName] ?? null;
        }
        return $storedFields;
    }
}
