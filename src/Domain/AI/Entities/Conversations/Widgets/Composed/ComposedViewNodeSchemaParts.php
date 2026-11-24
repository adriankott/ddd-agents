<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets\Composed;

use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\DataSources\ComposedViewDataSourceClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\Composed\EntityReferences\EntityReferenceResolverClasses;
use DDD\Domain\AI\Entities\Conversations\Widgets\ConversationWidgetClasses;
use DDD\Infrastructure\Services\DDDService;
use stdClass;

/**
 * The two committed files of the `ComposedView` node schema, built from the registered classes
 * ({@see ComposedViewNodeSchema::build()}) and split by the class that declares each type:
 *
 * - the module's schema: the whole schema with only the node types, widgets, data sources and entity types the
 *   classes of the DDD modules declare (the file the module's frontend ships);
 * - the application's part: only the node types, widgets, data sources and entity types the application's classes
 *   declare. The frontend adds it to the module's schema at start.
 *
 * A type is the module's when a class of the module namespace ({@see DDDService::FRAMEWORK_ROOT_NAMESPACE}) declares
 * it with the same type name — an application subclass that overrides a module class keeps the module's type. Reads
 * nothing but the class registries.
 */
class ComposedViewNodeSchemaParts
{
    /** @var string The note the application's part carries in place of the schema's `generatedBy` */
    public const string APPLICATION_SCHEMA_PART_GENERATED_BY = 'The application\'s part of the ComposedView node schema (its node types, widgets, data sources and entity types), written by app:agent:export-composed-view-node-schema from the application\'s classes — never edit by hand';

    /**
     * The module's schema: the full schema, restricted to the types the module declares.
     *
     * @return array<string, mixed>
     */
    public static function buildModuleSchema(): array
    {
        $schema = ComposedViewNodeSchema::build();
        $schema['nodeTypes'] = static::buildSchemaObject(static::filterEntriesByDeclaringSide((array)$schema['nodeTypes'], ComposedViewNodeClasses::getNodeClassesByNodeType(), 'NODE_TYPE', true));
        $schema['embeddableWidgets'] = static::buildSchemaObject(static::filterEntriesByDeclaringSide((array)$schema['embeddableWidgets'], ConversationWidgetClasses::getWidgetClassesByWidgetType(), 'WIDGET_TYPE', true));
        $schema['widgetTypes'] = static::filterTypesByDeclaringSide($schema['widgetTypes'], ConversationWidgetClasses::getWidgetClassesByWidgetType(), 'WIDGET_TYPE', true);
        $schema['dataSources'] = static::buildSchemaObject(static::filterEntriesByDeclaringSide((array)$schema['dataSources'], ComposedViewDataSourceClasses::getDataSourceClassesByName(), 'NAME', true));
        $schema['entityTypes'] = static::filterTypesByDeclaringSide($schema['entityTypes'], EntityReferenceResolverClasses::getResolverClassesByEntityType(), 'ENTITY_TYPE', true);
        return $schema;
    }

    /**
     * The application's part: the types the application's classes declare.
     *
     * @return array<string, mixed>
     */
    public static function buildApplicationSchemaPart(): array
    {
        $schema = ComposedViewNodeSchema::build();
        return [
            'version' => $schema['version'],
            'generatedBy' => self::APPLICATION_SCHEMA_PART_GENERATED_BY,
            'nodeTypes' => static::buildSchemaObject(static::filterEntriesByDeclaringSide((array)$schema['nodeTypes'], ComposedViewNodeClasses::getNodeClassesByNodeType(), 'NODE_TYPE', false)),
            'embeddableWidgets' => static::buildSchemaObject(static::filterEntriesByDeclaringSide((array)$schema['embeddableWidgets'], ConversationWidgetClasses::getWidgetClassesByWidgetType(), 'WIDGET_TYPE', false)),
            'widgetTypes' => static::filterTypesByDeclaringSide($schema['widgetTypes'], ConversationWidgetClasses::getWidgetClassesByWidgetType(), 'WIDGET_TYPE', false),
            'dataSources' => static::buildSchemaObject(static::filterEntriesByDeclaringSide((array)$schema['dataSources'], ComposedViewDataSourceClasses::getDataSourceClassesByName(), 'NAME', false)),
            'entityTypes' => static::filterTypesByDeclaringSide($schema['entityTypes'], EntityReferenceResolverClasses::getResolverClassesByEntityType(), 'ENTITY_TYPE', false),
        ];
    }

    /** @return string the module's schema as the committed file holds it */
    public static function buildModuleSchemaJson(): string
    {
        return static::encodeSchemaJson(static::buildModuleSchema());
    }

    /** @return string the application's part as the committed file holds it */
    public static function buildApplicationSchemaPartJson(): string
    {
        return static::encodeSchemaJson(static::buildApplicationSchemaPart());
    }

    /**
     * Whether the module declares the type of `$className`: the class itself or one of its parents lies in the module
     * namespace and carries the same value in `$typeConstantName`.
     *
     * @param class-string $className
     * @param string $typeConstantName
     * @return bool
     */
    public static function isTypeDeclaredByModule(string $className, string $typeConstantName): bool
    {
        $typeName = constant($className . '::' . $typeConstantName);
        for ($declaringClassName = $className; $declaringClassName !== false; $declaringClassName = get_parent_class($declaringClassName)) {
            if (
                str_starts_with($declaringClassName, DDDService::FRAMEWORK_ROOT_NAMESPACE . '\\')
                && defined($declaringClassName . '::' . $typeConstantName)
                && constant($declaringClassName . '::' . $typeConstantName) === $typeName
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * The entries (keyed by type) whose registered class is declared by the module (`$keepModuleTypes`) or by the
     * application (otherwise); a type without a registered class is the application's.
     *
     * @param array<string, mixed> $entriesByType
     * @param array<string, class-string> $classesByType
     * @param string $typeConstantName
     * @param bool $keepModuleTypes
     * @return array<string, mixed>
     */
    public static function filterEntriesByDeclaringSide(array $entriesByType, array $classesByType, string $typeConstantName, bool $keepModuleTypes): array
    {
        $keptEntriesByType = [];
        foreach ($entriesByType as $typeName => $entry) {
            if (static::isTypeOfModule((string)$typeName, $classesByType, $typeConstantName) === $keepModuleTypes) {
                $keptEntriesByType[$typeName] = $entry;
            }
        }
        return $keptEntriesByType;
    }

    /**
     * The type names whose registered class is declared by the module (`$keepModuleTypes`) or by the application.
     *
     * @param string[] $typeNames
     * @param array<string, class-string> $classesByType
     * @param string $typeConstantName
     * @param bool $keepModuleTypes
     * @return string[]
     */
    public static function filterTypesByDeclaringSide(array $typeNames, array $classesByType, string $typeConstantName, bool $keepModuleTypes): array
    {
        $keptTypeNames = [];
        foreach ($typeNames as $typeName) {
            if (static::isTypeOfModule($typeName, $classesByType, $typeConstantName) === $keepModuleTypes) {
                $keptTypeNames[] = $typeName;
            }
        }
        return $keptTypeNames;
    }

    /**
     * @param string $typeName
     * @param array<string, class-string> $classesByType
     * @param string $typeConstantName
     * @return bool
     */
    protected static function isTypeOfModule(string $typeName, array $classesByType, string $typeConstantName): bool
    {
        return isset($classesByType[$typeName]) && static::isTypeDeclaredByModule($classesByType[$typeName], $typeConstantName);
    }

    /**
     * An empty map as a JSON object, as the schema writes it.
     *
     * @param array<string, mixed> $entriesByType
     * @return array<string, mixed>|stdClass
     */
    protected static function buildSchemaObject(array $entriesByType): array|stdClass
    {
        return $entriesByType !== [] ? $entriesByType : new stdClass();
    }

    /**
     * The schema as the committed files hold it: pretty JSON, unescaped slashes and unicode, one trailing newline
     * ({@see ComposedViewNodeSchema::buildJson()}).
     *
     * @param array<string, mixed> $schema
     * @return string
     */
    protected static function encodeSchemaJson(array $schema): string
    {
        return json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
