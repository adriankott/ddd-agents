<?php

declare(strict_types=1);

namespace DDD\Testing\Agents\Surface;

use DDD\Domain\AI\Entities\MCPPackages\MCPBundleDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackageDocumentation;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlay;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolOverlays;
use DDD\Domain\AI\Entities\MCPPackages\ToolDefinitions;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Conversations\Purposes\AIConversationPurposesService;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Services\DDDService;
use JsonException;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Builds the surface document ({@see AgentSurfaceDocument}) of an application: what its agent is offered, as the
 * running container yields it, with the application's bindings, overlays, rights mapping and texts applied.
 *
 * The document has five parts, each a tree of maps:
 * - `skillOrder`: the catalog's skill names in the order the agent is offered them (a list: one value, the
 *   application's own, never compared with another document's);
 * - `skills`: every catalog skill by name: its public fields, its tool names, the packages it includes;
 * - `packages`: every package a skill includes, by short class name ({@see buildPackageKeysByClass()}): its id, per
 *   tool what its method declares for the loop and what the application's overlay sets
 *   ({@see buildDeclarationEntriesByToolName()}), and
 *   per tool its fields, one value per parameter of the documented schema (what an MCP client reads), and the schema
 *   the loop puts on the wire (`wire`: its description, one hash token per parameter, the rest);
 * - `purposes`: the purposes the caller names: class and public fields;
 * - `bundles`: the HTTP MCP bundles the caller names: one value per tool.
 *
 * A value is kept plain while it is short (a name, a flag, a list of names, a small map) and replaced by a hash token
 * with its length when it is a long text or a larger structure ({@see condenseValue()}). A field that holds nothing
 * (null, false, an empty text, an empty list) is left out ({@see condenseFields()}): a missing key reads "not set". So
 * a document stays small, and a comparison ({@see AgentSurfaceDocumentComparer}) names the tool and the parameter that
 * changed without carrying their texts.
 *
 * Needs the application's container (the skill, purpose and package services); it boots nothing itself.
 */
class AgentSurfaceDocumentBuilder
{
    /** A text up to this length is kept as it is; a longer one is replaced by its hash token */
    public const int MAXIMUM_PLAIN_TEXT_CHARACTERS = 120;

    /**
     * A map whose JSON is up to this length is kept as a map of condensed values; a larger one is one hash token. The
     * trade between a readable failure and the file's size: a small parameter schema (`{"type": "integer"}`) stays
     * readable field by field, a larger one is one token. Raising it makes the pinned files grow with every parameter
     * schema it lets through; lowering it to 0 turns every map into a token.
     */
    public const int MAXIMUM_PLAIN_MAP_CHARACTERS = 240;

    /** The hexadecimal characters of the SHA-1 a hash token carries: enough to tell a changed value from an unchanged one */
    public const int HASH_TOKEN_CHARACTERS = 12;

    /** The fields of a skill, a tool and a purpose that are no part of what the agent is offered */
    public const array FIELDS_OUTSIDE_THE_SURFACE = ['objectType'];

    /** The arguments of the bundle documentation as the MCP controller builds its `tools/list` answer */
    public const int BUNDLE_DOCUMENTATION_SCHEMA_DEPTH = 6;

    /**
     * @param string[] $purposeNames The purposes to document, as dotted names
     * @param string[] $bundleClasses The HTTP MCP bundle classes to document
     * @throws JsonException
     */
    public function buildSurfaceDocument(array $purposeNames = [], array $bundleClasses = []): AgentSurfaceDocument
    {
        /** @var AISkillsService $aISkillsService */
        $aISkillsService = DDDService::instance()->getService(AISkillsService::class);
        $skillOrder = array_values(AISkill::getAllSkillNames());
        $skillNames = $skillOrder;
        sort($skillNames);
        $aISkillsByName = [];
        $packageClasses = [];
        foreach ($skillNames as $skillName) {
            $aISkillsByName[$skillName] = $aISkillsService->getAISkillByName($skillName);
            foreach ($aISkillsByName[$skillName]?->getPackages() ?? [] as $packageClass) {
                $packageClasses[$packageClass] = $packageClass;
            }
        }
        $packageKeysByClass = $this->buildPackageKeysByClass(array_values($packageClasses));

        $skillEntries = [];
        foreach ($aISkillsByName as $skillName => $aISkill) {
            $skillEntries[$skillName] = $aISkill === null ? ['isLoadable' => false] : $this->buildSkillEntry($aISkill, $packageKeysByClass);
        }
        $packageEntries = [];
        foreach ($packageKeysByClass as $packageClass => $packageKey) {
            $packageEntries[$packageKey] = $this->buildPackageEntry($packageClass);
        }
        ksort($packageEntries);

        $purposeEntries = [];
        sort($purposeNames);
        foreach ($purposeNames as $purposeName) {
            $purposeEntries[$purposeName] = $this->buildPurposeEntry($purposeName);
        }

        $bundleEntries = [];
        sort($bundleClasses);
        foreach ($bundleClasses as $bundleClass) {
            $bundleEntries[$bundleClass] = $this->buildBundleEntry($bundleClass);
        }

        return new AgentSurfaceDocument($skillOrder, $skillEntries, $packageEntries, $purposeEntries, $bundleEntries);
    }

    /**
     * The key of every package class in the document: its short class name, so that an application's subclass at the
     * path of a package's class has the key of the class it replaces; the full class name where two classes of the
     * document share a short name.
     *
     * @param string[] $packageClasses
     * @return array<string, string> Package class => key
     */
    public function buildPackageKeysByClass(array $packageClasses): array
    {
        $classCountsByShortName = [];
        foreach ($packageClasses as $packageClass) {
            $shortClassName = $this->buildShortClassName($packageClass);
            $classCountsByShortName[$shortClassName] = ($classCountsByShortName[$shortClassName] ?? 0) + 1;
        }
        $packageKeysByClass = [];
        foreach ($packageClasses as $packageClass) {
            $shortClassName = $this->buildShortClassName($packageClass);
            $packageKeysByClass[$packageClass] = $classCountsByShortName[$shortClassName] === 1 ? $shortClassName : $packageClass;
        }
        return $packageKeysByClass;
    }

    /**
     * @param array<string, string> $packageKeysByClass The keys of the document's packages ({@see buildPackageKeysByClass()})
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function buildSkillEntry(AISkill $aISkill, array $packageKeysByClass = []): array
    {
        $skillEntry = ['isLoadable' => true];
        foreach ($this->condenseFields(get_object_vars($aISkill)) as $fieldName => $fieldValue) {
            $skillEntry[$fieldName] = $fieldValue;
        }
        $skillEntry['toolNames'] = array_values($aISkill->getToolNames());
        $packageKeys = [];
        foreach ($aISkill->getPackages() as $packageClass) {
            $packageKeys[] = $packageKeysByClass[$packageClass] ?? $this->buildShortClassName($packageClass);
        }
        $skillEntry['packages'] = $packageKeys;
        return $skillEntry;
    }

    /**
     * One package: its id, its own fields and its tools. Every tool carries its fields, one value per parameter (the
     * hash token of that parameter's schema) and its schema on the wire ({@see buildWireEntriesByToolName()}).
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function buildPackageEntry(string $packageClass): array
    {
        $packageDocumentation = $this->convertToPlainArray(MCPPackageDocumentation::createFromClassName($packageClass));
        $wireEntriesByToolName = $this->buildWireEntriesByToolName($packageClass);
        $declarationEntriesByToolName = $this->buildDeclarationEntriesByToolName($packageClass);
        $toolEntries = [];
        foreach ($packageDocumentation['functions'] ?? [] as $toolDocumentation) {
            $toolName = (string)($toolDocumentation['name'] ?? '');
            $toolEntries[$toolName] = $this->buildToolEntry($toolDocumentation);
            foreach ($declarationEntriesByToolName[$toolName] ?? [] as $declarationPartName => $declarationPart) {
                $toolEntries[$toolName][$declarationPartName] = $declarationPart;
            }
            if (isset($wireEntriesByToolName[$toolName])) {
                $toolEntries[$toolName]['wire'] = $wireEntriesByToolName[$toolName];
            }
        }
        unset($packageDocumentation['functions']);
        $packageEntry = ['packageId' => $packageDocumentation['name'] ?? null];
        unset($packageDocumentation['name']);
        foreach ($this->condenseFields($packageDocumentation) as $fieldName => $fieldValue) {
            $packageEntry[$fieldName] = $fieldValue;
        }
        $packageEntry['tools'] = $toolEntries;
        return $packageEntry;
    }

    /**
     * What the tools of a package declare for the loop beside their documentation, by tool name:
     * - `declarations`: the attributes of the tool's `#[MCPTool]` method, short class name => the declared arguments
     *   (`true` for an attribute without arguments). These are the declarations the loop and its readers ask by tool
     *   name ({@see \DDD\Domain\AI\Entities\MCPPackages\MCPToolAttributeCatalog}): the subject a run report names,
     *   whether the newest result is never stubbed, whether a scheduled run may call the tool, …;
     * - `overlay`: what the application's overlay sets for the tool (`AI.toolOverlays`,
     *   {@see buildOverlayEntry()}).
     * A part that holds nothing is left out.
     *
     * @return array<string, array<string, mixed>>
     * @throws JsonException
     */
    public function buildDeclarationEntriesByToolName(string $packageClass): array
    {
        $applicationOverlays = MCPToolOverlays::getApplicationOverlays();
        $declarationEntriesByToolName = [];
        foreach ((new ReflectionClass($packageClass))->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            if ($reflectionMethod->getAttributes(MCPTool::class) === []) {
                continue;
            }
            $toolName = $reflectionMethod->getName();
            $declarationEntry = [];
            $declarations = $this->buildDeclarationsOfToolMethod($reflectionMethod);
            if ($declarations !== []) {
                $declarationEntry['declarations'] = $declarations;
            }
            $toolOverlay = $applicationOverlays->findOverlayOfTool($toolName);
            $overlayEntry = $toolOverlay !== null ? $this->buildOverlayEntry($toolOverlay) : [];
            if ($overlayEntry !== []) {
                $declarationEntry['overlay'] = $overlayEntry;
            }
            if ($declarationEntry !== []) {
                $declarationEntriesByToolName[$toolName] = $declarationEntry;
            }
        }
        return $declarationEntriesByToolName;
    }

    /**
     * What the application's overlay sets for one tool: `sets`, the keys of its entry in `AI.toolOverlays` that the
     * overlay reads a value from (one value), and `ownerFacingSubject`, the one key whose value shows nowhere else in
     * the tool's entry. The texts, rights and parameters an overlay replaces are the tool's own fields and its schema:
     * a changed overlay text is one changed value there, and `sets` says that it came from the overlay.
     *
     * @return array<string, mixed>
     */
    public function buildOverlayEntry(MCPToolOverlay $toolOverlay): array
    {
        $setKeys = [];
        if ($toolOverlay->replacesAccountRights()) {
            $setKeys[] = MCPToolOverlay::KEY_ACCOUNT_RIGHTS;
        }
        if ($toolOverlay->description !== null) {
            $setKeys[] = MCPToolOverlay::KEY_DESCRIPTION;
        }
        if ($toolOverlay->examples !== null) {
            $setKeys[] = MCPToolOverlay::KEY_EXAMPLES;
        }
        if ($toolOverlay->ownerFacingSubject !== null) {
            $setKeys[] = MCPToolOverlay::KEY_OWNER_FACING_SUBJECT;
        }
        if ($toolOverlay->parameterDescriptions !== [] || $toolOverlay->hiddenParameterNames !== []) {
            $setKeys[] = MCPToolOverlay::KEY_PARAMETERS;
        }
        if ($toolOverlay->summary !== null) {
            $setKeys[] = MCPToolOverlay::KEY_SUMMARY;
        }
        if ($setKeys === []) {
            return [];
        }
        $overlayEntry = ['sets' => $setKeys];
        if ($toolOverlay->ownerFacingSubject !== null) {
            $overlayEntry['ownerFacingSubject'] = $toolOverlay->ownerFacingSubject;
        }
        return $overlayEntry;
    }

    /**
     * The attributes of one tool method but `#[MCPTool]` itself (its values are the tool's documented fields): short
     * class name => the declared arguments, condensed; `true` for an attribute without arguments. A second attribute
     * of a class is keyed `<name> #2`.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function buildDeclarationsOfToolMethod(ReflectionMethod $reflectionMethod): array
    {
        $declarations = [];
        foreach ($reflectionMethod->getAttributes() as $reflectionAttribute) {
            if ($reflectionAttribute->getName() === MCPTool::class) {
                continue;
            }
            $attributeShortName = $this->buildShortClassName($reflectionAttribute->getName());
            $declarationKey = $attributeShortName;
            for ($occurrence = 2; isset($declarations[$declarationKey]); $occurrence++) {
                $declarationKey = $attributeShortName . ' #' . $occurrence;
            }
            try {
                $declaredArguments = $reflectionAttribute->getArguments();
            } catch (Throwable) {
                $declarations[$declarationKey] = '(arguments not readable)';
                continue;
            }
            $declarations[$declarationKey] = $declaredArguments === [] ? true : $this->condenseValue($this->convertToPlainArray($declaredArguments));
        }
        ksort($declarations);
        return $declarations;
    }

    /**
     * One tool of a package documentation: its fields, and its input schema split into one value per parameter (a
     * small schema as it is, a larger one as its hash token) and the rest of the schema.
     *
     * @param array<string, mixed> $toolDocumentation
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function buildToolEntry(array $toolDocumentation): array
    {
        $inputSchema = is_array($toolDocumentation['inputSchema'] ?? null) ? $toolDocumentation['inputSchema'] : [];
        unset($toolDocumentation['inputSchema'], $toolDocumentation['name']);
        $toolEntry = $this->condenseFields($toolDocumentation);
        $parameterEntries = [];
        foreach (is_array($inputSchema['properties'] ?? null) ? $inputSchema['properties'] : [] as $parameterName => $parameterSchema) {
            $parameterEntries[(string)$parameterName] = $this->condenseValue($parameterSchema);
        }
        unset($inputSchema['properties']);
        $toolEntry['parameters'] = $parameterEntries;
        $toolEntry['inputSchemaWithoutParameters'] = $this->buildHash($inputSchema);
        return $toolEntry;
    }

    /**
     * Every tool's schema as the loop puts it on the wire, by tool name, in parts: the description, one value per
     * parameter, the required parameters and the rest of the schema. A tool without a wire schema has no entry. No
     * value covers the whole schema, so a change of one parameter shows in that parameter only.
     *
     * @return array<string, array<string, mixed>>
     * @throws JsonException
     */
    public function buildWireEntriesByToolName(string $packageClass): array
    {
        $wireEntriesByToolName = [];
        foreach (ToolDefinitions::forPackages([$packageClass])->toOpenAiToolSchemas() as $openAiToolSchema) {
            $openAiToolSchema = $this->convertToPlainArray($openAiToolSchema);
            $toolName = (string)($openAiToolSchema['function']['name'] ?? '');
            if ($toolName === '') {
                continue;
            }
            $wireParameters = is_array($openAiToolSchema['function']['parameters'] ?? null) ? $openAiToolSchema['function']['parameters'] : [];
            $wireParameterEntries = [];
            foreach (is_array($wireParameters['properties'] ?? null) ? $wireParameters['properties'] : [] as $parameterName => $parameterSchema) {
                $wireParameterEntries[(string)$parameterName] = $this->buildHash($parameterSchema);
            }
            $wireEntry = $this->condenseFields([
                'description' => $openAiToolSchema['function']['description'] ?? null,
                'requiredParameters' => array_values(is_array($wireParameters['required'] ?? null) ? $wireParameters['required'] : []),
            ]);
            $wireEntry['parameters'] = $wireParameterEntries;
            unset($openAiToolSchema['function']['description'], $openAiToolSchema['function']['parameters']['properties'], $openAiToolSchema['function']['parameters']['required']);
            $wireEntry['rest'] = $this->buildHash($openAiToolSchema);
            $wireEntriesByToolName[$toolName] = $wireEntry;
        }
        return $wireEntriesByToolName;
    }

    /**
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function buildPurposeEntry(string $purposeName): array
    {
        /** @var AIConversationPurposesService $aIConversationPurposesService */
        $aIConversationPurposesService = DDDService::instance()->getService(AIConversationPurposesService::class);
        $aIConversationPurpose = $aIConversationPurposesService->getPurposeByName($purposeName);
        if ($aIConversationPurpose === null) {
            return ['isLoadable' => false];
        }
        $purposeEntry = ['isLoadable' => true, 'class' => $this->buildShortClassName(get_class($aIConversationPurpose))];
        foreach ($this->condenseFields($this->convertToPlainArray(get_object_vars($aIConversationPurpose))) as $fieldName => $fieldValue) {
            $purposeEntry[$fieldName] = $fieldValue;
        }
        return $purposeEntry;
    }

    /**
     * One HTTP MCP bundle: its own fields and one value per tool (the hash of the tool's `tools/list` entry).
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function buildBundleEntry(string $bundleClass): array
    {
        $bundleDocumentation = $this->convertToPlainArray(MCPBundleDocumentation::createFromClassName($bundleClass, false, true, static::BUNDLE_DOCUMENTATION_SCHEMA_DEPTH));
        $toolHashesByName = [];
        foreach ($bundleDocumentation['tools'] ?? [] as $toolDocumentation) {
            $toolHashesByName[(string)($toolDocumentation['name'] ?? '')] = $this->buildHash($toolDocumentation);
        }
        unset($bundleDocumentation['tools']);
        $bundleEntry = $this->condenseFields($bundleDocumentation);
        $bundleEntry['tools'] = $toolHashesByName;
        return $bundleEntry;
    }

    /**
     * The fields of an entry, each condensed, sorted by name; without the ones outside the surface and without the
     * ones that hold nothing (null, false, an empty text, an empty list or map).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function condenseFields(array $fields): array
    {
        $condensedFields = [];
        foreach ($fields as $fieldName => $fieldValue) {
            if (in_array($fieldName, static::FIELDS_OUTSIDE_THE_SURFACE, true) || $this->holdsNothing($fieldValue)) {
                continue;
            }
            $condensedFields[(string)$fieldName] = $this->condenseValue($fieldValue);
        }
        ksort($condensedFields);
        return $condensedFields;
    }

    public function holdsNothing(mixed $value): bool
    {
        return $value === null || $value === false || $value === '' || $value === [];
    }

    /**
     * A value as the document holds it: null, a bool, a number and a short text as they are; a list of such values as
     * a list; a small map as a map of condensed values (the comparison walks it); a long text and every other
     * structure as one hash token.
     *
     * @throws JsonException
     */
    public function condenseValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_strlen($value) <= static::MAXIMUM_PLAIN_TEXT_CHARACTERS ? $value : $this->buildHash($value);
        }
        if (!is_array($value)) {
            $value = $this->convertToPlainArray($value);
        }
        if (array_is_list($value)) {
            return $this->isListOfShortPlainValues($value) ? $value : $this->buildHash($value);
        }
        if (mb_strlen(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) <= static::MAXIMUM_PLAIN_MAP_CHARACTERS) {
            return $this->condenseFields($value);
        }
        return $this->buildHash($value);
    }

    /**
     * @param array<int, mixed> $list
     */
    public function isListOfShortPlainValues(array $list): bool
    {
        foreach ($list as $listElement) {
            if (is_string($listElement) && mb_strlen($listElement) <= static::MAXIMUM_PLAIN_TEXT_CHARACTERS) {
                continue;
            }
            if ($listElement === null || is_bool($listElement) || is_int($listElement) || is_float($listElement)) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * The hash token of a value: `#<first characters of its SHA-1> (<n> characters)`. A text is hashed as it is, a
     * structure as its JSON; nothing in either depends on the day of the run.
     *
     * @throws JsonException
     */
    public function buildHash(mixed $value): string
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return sprintf('#%s (%d characters)', substr(sha1($text), 0, static::HASH_TOKEN_CHARACTERS), mb_strlen($text));
    }

    public function buildShortClassName(string $className): string
    {
        $classNameParts = explode('\\', $className);
        return (string)end($classNameParts);
    }

    /**
     * A serializable value as plain arrays, the way it is written as JSON.
     *
     * @return array<string|int, mixed>
     * @throws JsonException
     */
    protected function convertToPlainArray(mixed $value): array
    {
        $decodedValue = json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        return is_array($decodedValue) ? $decodedValue : [];
    }
}
