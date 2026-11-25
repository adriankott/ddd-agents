<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\Base\Entities\StaticRegistry;

/**
 * The builders of {@see TaskPlanCharacterizationTestCase} over the MODULE's own item class: the tables of these tests
 * pin what the module answers for a host that declares no item field of its own, so the module class stands in the
 * place {@see TaskPlanCharacterizationTestCase} gives to an application's class. The parent's tear-down puts the
 * mapping back.
 */
abstract class TaskPlanModuleItemTestCase extends TaskPlanCharacterizationTestCase
{
    /** The item fields a platform closing or re-open of the module may write, in the order the field-write rows list them. */
    protected const array MODULE_MUTABLE_ITEM_FIELDS = [
        'status', 'statusNote', 'requiresUserAction', 'retryable',
        'platformResolvedAtMessageIndex', 'evidenceMessageIndex', 'preResolvedAtSeed', 'offerRenderedAtMessageIndex',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        StaticRegistry::$frameworkToAppClassNames[TaskPlanItem::class] = TaskPlanItem::class;
    }

    /** @param array<string, mixed> $fields */
    protected function itemWithFields(string $text, array $fields = []): TaskPlanItem
    {
        $taskPlanItem = $this->item($text);
        foreach ($fields as $fieldName => $value) {
            $taskPlanItem->{$fieldName} = $value;
        }
        return $taskPlanItem;
    }

    /**
     * The field-write row of one item: every field a closing or re-open of the module may write, by name.
     *
     * @return array<string, mixed>
     */
    protected function moduleMutableFieldsOf(TaskPlanItem $taskPlanItem): array
    {
        $mutableFields = [];
        foreach (self::MODULE_MUTABLE_ITEM_FIELDS as $fieldName) {
            $mutableFields[$fieldName] = $taskPlanItem->{$fieldName};
        }
        return $mutableFields;
    }

    /** An item whose module fields that a closing may write all carry a recognisable value, so a row shows what a writer leaves untouched. */
    protected function moduleSentinelItem(string $text, string $status = TaskPlanItem::STATUS_OPEN, ?int $platformResolvedAtMessageIndex = 3): TaskPlanItem
    {
        return $this->itemWithFields($text, [
            'status' => $status,
            'statusNote' => 'SENTINEL note',
            'requiresUserAction' => true,
            'retryable' => true,
            'platformResolvedAtMessageIndex' => $platformResolvedAtMessageIndex,
            'evidenceMessageIndex' => 77,
            'preResolvedAtSeed' => true,
            'offerRenderedAtMessageIndex' => 66,
        ]);
    }

    /**
     * The sentinel row with the given fields overridden: the expected row of a field-write case.
     *
     * @param array<string, mixed> $writtenFields
     * @return array<string, mixed>
     */
    protected function moduleSentinelFieldsWith(string $status, array $writtenFields, ?int $platformResolvedAtMessageIndex = 3): array
    {
        return array_merge([
            'status' => $status,
            'statusNote' => 'SENTINEL note',
            'requiresUserAction' => true,
            'retryable' => true,
            'platformResolvedAtMessageIndex' => $platformResolvedAtMessageIndex,
            'evidenceMessageIndex' => 77,
            'preResolvedAtSeed' => true,
            'offerRenderedAtMessageIndex' => 66,
        ], $writtenFields);
    }
}
