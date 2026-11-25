<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\TaskPlan\Characterization;

use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlan;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItem;
use DDD\Domain\AI\Entities\Conversations\Agent\TaskPlanItems;
use ReflectionClass;
use ReflectionProperty;

/**
 * The field lists of the module's own item class ({@see TaskPlanItem}, no application class in its place): the keys it
 * persists in their order, how they split into declarations, platform records, state and identity, what the seed parse
 * reads and normalises ({@see TaskPlanItems::fromPurposeSeedDefinition()}), what the repository read gives back for a
 * full item and for legacy or malformed values ({@see TaskPlanItem::fromRepositoryFields()}), the persisted bytes of
 * four representative items (as hashes, one in full), their identity keys, and the keys of the plan slice. The list of
 * an application's item class is the package's {@see TaskPlanCharacterizationTestCase::PERSISTED_ITEM_KEYS}.
 */
class TaskPlanModuleItemFieldListsTest extends TaskPlanModuleItemTestCase
{
    /** The 39 keys the module's item persists, in their order; the identity fields stand after the text. */
    protected const array MODULE_PERSISTED_ITEM_KEYS = [
        'text', 'key', 'highestSubtaskKeyNumber', 'seededByPurpose', 'ownerFacingTitle', 'status', 'statusNote', 'requiresUserAction', 'retryable', 'doneCriterion',
        'doneRequiresToolCall', 'doneRequiresToolCallArgumentsAbsent', 'doneRequiresToolCallArgumentsPresent', 'notApplicableRequiresToolCall',
        'notApplicableRequiresToolCallArgumentsPresent', 'notApplicableRequiresToolCallArgumentsAbsent', 'notApplicableForbiddenAfterToolCall',
        'doneRequiresWidget', 'doneRequiresConnectedChannel', 'closedQuestionOptions', 'minReasoningEffort', 'doneRequiresWidgetFilledFields', 'doneRequiresWidgetAnswered',
        'doneRequiresOwnerVisibleAnswerInSameTurn', 'doneRequiresOwnerQuestionKey', 'doneAlsoOnOwnerAnswerOfWidget', 'doneOwnerQuestionMayFollowToolCall',
        'previousTurnDeliveryEvidenceToolNames', 'evidenceMessageIndex', 'offerRenderedAtMessageIndex', 'preResolvedAtSeed', 'platformResolvedAtMessageIndex',
        'blockedRequiresWidget', 'blockedRequiresToolCall', 'blockedRequiresToolCallArgumentsPresent', 'triggersSupervisorMilestoneCheckpoint',
        'customerProgressStepKey', 'skillNames', 'subtasks',
    ];

    /** The 26 declarations a seed may set on the module's item. */
    protected const array MODULE_DECLARATION_FIELDS = [
        'ownerFacingTitle', 'doneCriterion', 'doneRequiresToolCall', 'doneRequiresToolCallArgumentsAbsent', 'doneRequiresToolCallArgumentsPresent',
        'notApplicableRequiresToolCall', 'notApplicableRequiresToolCallArgumentsAbsent', 'notApplicableRequiresToolCallArgumentsPresent',
        'notApplicableForbiddenAfterToolCall', 'doneRequiresWidget', 'doneRequiresConnectedChannel', 'closedQuestionOptions', 'minReasoningEffort',
        'doneRequiresWidgetFilledFields', 'doneRequiresWidgetAnswered', 'doneRequiresOwnerVisibleAnswerInSameTurn', 'doneRequiresOwnerQuestionKey',
        'doneAlsoOnOwnerAnswerOfWidget', 'doneOwnerQuestionMayFollowToolCall', 'previousTurnDeliveryEvidenceToolNames', 'blockedRequiresWidget',
        'blockedRequiresToolCall', 'blockedRequiresToolCallArgumentsPresent', 'triggersSupervisorMilestoneCheckpoint', 'customerProgressStepKey', 'skillNames',
    ];

    /** The 4 platform records the module's item persists always. */
    protected const array MODULE_PLATFORM_RECORD_FIELDS = ['evidenceMessageIndex', 'offerRenderedAtMessageIndex', 'preResolvedAtSeed', 'platformResolvedAtMessageIndex'];

    /** The platform record persisted only when set, after the subtasks. */
    protected const array RECORD_FIELDS_STORED_ONLY_WHEN_SET = ['answerTextHeldOpenAtMessageIndex'];

    protected const array ITEM_STATE_FIELDS = ['text', 'status', 'statusNote', 'requiresUserAction', 'retryable', 'subtasks'];

    // --- the representative items ---------------------------------------------------------------------------------------

    /** @return array<string, mixed> one seed item with every declaration set, each value chosen to show its normaliser */
    protected function seedItemWithEveryDeclaration(): array
    {
        return [
            'text' => '  Step 1 — Confirm the entry at /profile  ',
            'ownerFacingTitle' => '  Bestätigen Sie den Eintrag ',
            'doneCriterion' => ' entry.confirmed ',
            'doneRequiresToolCall' => 'confirmEntry|updateEntry',
            'doneRequiresToolCallArgumentsAbsent' => ['statedByOwner' => [false]],
            'doneRequiresToolCallArgumentsPresent' => ['areasStatedByOwner' => [true]],
            'notApplicableRequiresToolCall' => 'saveStatement',
            'notApplicableRequiresToolCallArgumentsAbsent' => ['topics' => ['privacy']],
            'notApplicableRequiresToolCallArgumentsPresent' => ['kind' => ['DECLINE', 'DEFERRAL']],
            'notApplicableForbiddenAfterToolCall' => 'saveLinks',
            'doneRequiresWidget' => 'ChoiceWidget',
            'doneRequiresConnectedChannel' => ' Calendar ',
            'closedQuestionOptions' => ['DE' => ['Ja', ' Nein ', 'Ja'], 'en' => ['Yes', 'No'], 'xx-long' => ['A', 'B'], 'fr' => ['Oui']],
            'minReasoningEffort' => 'Medium',
            'doneRequiresWidgetFilledFields' => ['promise' => ['date' => '<YYYY-MM-DD>', 'topic' => '<what you deliver>']],
            'doneRequiresWidgetAnswered' => true,
            'doneRequiresOwnerVisibleAnswerInSameTurn' => true,
            'doneRequiresOwnerQuestionKey' => ' approvesDraft ',
            'doneAlsoOnOwnerAnswerOfWidget' => 'OfferWidget',
            'doneOwnerQuestionMayFollowToolCall' => ' proposeChoices ',
            'previousTurnDeliveryEvidenceToolNames' => ['readRanking', 7],
            'blockedRequiresWidget' => 'HandoverWidget',
            'blockedRequiresToolCall' => 'saveStatement',
            'blockedRequiresToolCallArgumentsPresent' => ['topics' => ['access']],
            'triggersSupervisorMilestoneCheckpoint' => true,
            'customerProgressStepKey' => 'step.one',
            'skillNames' => [' Skill.One ', '', 'Skill.Two'],
        ];
    }

    protected function seededLeafWithEveryDeclaration(): TaskPlanItem
    {
        return TaskPlanItems::fromPurposeSeedDefinition([$this->seedItemWithEveryDeclaration()])->first();
    }

    protected function containerWithSubtasks(): TaskPlanItem
    {
        return TaskPlanItems::fromPurposeSeedDefinition([[
            'text' => 'Step 2 — Profile round',
            'customerProgressStepKey' => 'step.two',
            'skillNames' => ['Skill.Two'],
            'subtasks' => [
                'Description line',
                ['text' => 'Contact line', 'doneCriterion' => 'contact.reachable', 'customerProgressStepKey' => 'step.contact'],
                ['text' => '   '],
            ],
        ]])->first();
    }

    protected function platformClosedLeafWithRecords(): TaskPlanItem
    {
        $leaf = $this->item('Rivals saved', TaskPlanItem::STATUS_DONE, 'closed by the platform: addRivals COMPLETED @12');
        $leaf->doneRequiresToolCall = 'addRivals|updateRivals';
        $leaf->customerProgressStepKey = 'step.rivals';
        $leaf->evidenceMessageIndex = 14;
        $leaf->platformResolvedAtMessageIndex = 12;
        $leaf->preResolvedAtSeed = true;
        $leaf->offerRenderedAtMessageIndex = 9;
        return $leaf;
    }

    protected function legacyKeysItem(): TaskPlanItem
    {
        return TaskPlanItem::fromRepositoryFields([
            'text' => ' Old item ',
            'status' => 'blocked',
            'blockReason' => 'connection expired',
            'requiresUserAction' => 1,
            'evidenceMessageIndex' => 'abc',
            'offerRenderedAtMessageIndex' => 'abc',
            'platformResolvedAtMessageIndex' => '5',
            'skillNames' => [' Skill.Old ', 7, ''],
            'minReasoningEffort' => 'extreme',
            'doneRequiresConnectedChannel' => 'calendar',
            'doneRequiresToolCallArgumentsAbsent' => [],
            'subtasks' => [],
        ]);
    }

    /** @return array<string, TaskPlanItem> */
    protected function goldenItems(): array
    {
        return [
            'seeded leaf with every declaration' => $this->seededLeafWithEveryDeclaration(),
            'container with subtasks' => $this->containerWithSubtasks(),
            'platform-closed leaf with records' => $this->platformClosedLeafWithRecords(),
            'legacy keys item' => $this->legacyKeysItem(),
        ];
    }

    // --- the keys ---------------------------------------------------------------------------------------------------------

    public function testTheModulesItemPersistsItsThirtyNinePublicPropertiesInTheirOrder(): void
    {
        self::assertCount(39, self::MODULE_PERSISTED_ITEM_KEYS);
        self::assertSame(self::MODULE_PERSISTED_ITEM_KEYS, array_keys(TaskPlanItem::newInstance()->toRepositoryFields()));

        $publicPropertyNames = array_values(array_map(
            [self::class, 'propertyName'],
            array_filter((new ReflectionClass(TaskPlanItem::class))->getProperties(ReflectionProperty::IS_PUBLIC), [self::class, 'isAnItemField'])
        ));
        sort($publicPropertyNames);
        $storableKeys = array_merge(self::MODULE_PERSISTED_ITEM_KEYS, self::RECORD_FIELDS_STORED_ONLY_WHEN_SET);
        sort($storableKeys);
        self::assertSame($storableKeys, $publicPropertyNames, 'every declared property is persisted, nothing else');

        $partition = array_merge(self::MODULE_DECLARATION_FIELDS, self::MODULE_PLATFORM_RECORD_FIELDS, self::ITEM_STATE_FIELDS, self::ITEM_IDENTITY_FIELDS);
        sort($partition);
        $sortedPersistedKeys = self::MODULE_PERSISTED_ITEM_KEYS;
        sort($sortedPersistedKeys);
        self::assertSame($sortedPersistedKeys, $partition, '26 declarations + 4 records + 6 state fields + 3 identity fields');
    }

    public static function propertyName(ReflectionProperty $reflectionProperty): string
    {
        return $reflectionProperty->getName();
    }

    public static function isAnItemField(ReflectionProperty $reflectionProperty): bool
    {
        return !$reflectionProperty->isStatic() && $reflectionProperty->getName() !== 'objectType';
    }

    public function testARecordStoredOnlyWhenSetFollowsTheSubtasksAndRoundTrips(): void
    {
        $itemHeldOpenAfterAnAnswer = TaskPlanItem::newInstance();
        $itemHeldOpenAfterAnAnswer->answerTextHeldOpenAtMessageIndex = 7;

        self::assertSame(array_merge(self::MODULE_PERSISTED_ITEM_KEYS, self::RECORD_FIELDS_STORED_ONLY_WHEN_SET), array_keys($itemHeldOpenAfterAnAnswer->toRepositoryFields()));
        self::assertSame(7, TaskPlanItem::fromRepositoryFields($itemHeldOpenAfterAnAnswer->toRepositoryFields())->answerTextHeldOpenAtMessageIndex);
    }

    public function testTheRecordKeysStandBetweenTheDeclarations(): void
    {
        $positionByKey = array_flip(self::MODULE_PERSISTED_ITEM_KEYS);
        self::assertSame(
            ['previousTurnDeliveryEvidenceToolNames', 'evidenceMessageIndex', 'offerRenderedAtMessageIndex', 'preResolvedAtSeed', 'platformResolvedAtMessageIndex', 'blockedRequiresWidget'],
            array_slice(self::MODULE_PERSISTED_ITEM_KEYS, $positionByKey['previousTurnDeliveryEvidenceToolNames'], 6)
        );
    }

    public function testTheModulesItemDeclaresExactlyTheTwentySixDeclarations(): void
    {
        $declaredFields = array_keys(TaskPlanItem::newInstance()->getSeededDeclarationKinds());
        $expectedFields = self::MODULE_DECLARATION_FIELDS;
        sort($declaredFields);
        sort($expectedFields);

        self::assertSame($expectedFields, $declaredFields);
    }

    // --- the seed parse ---------------------------------------------------------------------------------------------------

    public function testTheSeedParseReadsEveryDeclarationButTheUndeclaredChannelAndNoRecord(): void
    {
        $seededLeaf = $this->seededLeafWithEveryDeclaration();
        $defaultItem = TaskPlanItem::newInstance();
        $unreadDeclarations = [];
        foreach (self::MODULE_DECLARATION_FIELDS as $declarationField) {
            if ($defaultItem->{$declarationField} === $seededLeaf->{$declarationField}) {
                $unreadDeclarations[] = $declarationField;
            }
        }
        self::assertSame(['doneRequiresConnectedChannel'], $unreadDeclarations, 'the module item declares no channel: every name reads as null');
        foreach (self::MODULE_PLATFORM_RECORD_FIELDS as $recordField) {
            self::assertSame($defaultItem->{$recordField}, $seededLeaf->{$recordField}, "the seed parse never writes the record {$recordField}");
        }
        self::assertSame(TaskPlanItem::STATUS_OPEN, $seededLeaf->status);
        self::assertTrue($seededLeaf->seededByPurpose);
    }

    public function testTheSeedParseNormalisesTrimsAndInheritsTheProgressStepKey(): void
    {
        $seededLeaf = $this->seededLeafWithEveryDeclaration();
        self::assertSame('Step 1 — Confirm the entry at /profile', $seededLeaf->text);
        self::assertSame('Bestätigen Sie den Eintrag', $seededLeaf->ownerFacingTitle);
        self::assertSame('entry.confirmed', $seededLeaf->doneCriterion);
        self::assertSame('approvesDraft', $seededLeaf->doneRequiresOwnerQuestionKey);
        self::assertSame('proposeChoices', $seededLeaf->doneOwnerQuestionMayFollowToolCall);
        self::assertSame(['de' => ['Ja', 'Nein'], 'en' => ['Yes', 'No']], $seededLeaf->closedQuestionOptions);
        self::assertSame('medium', $seededLeaf->minReasoningEffort);
        self::assertSame(['readRanking', '7'], $seededLeaf->previousTurnDeliveryEvidenceToolNames);
        self::assertSame(['Skill.One', 'Skill.Two'], $seededLeaf->skillNames, 'the seed TRIMS skill names and drops a blank one');

        $subtasks = array_values($this->containerWithSubtasks()->subtasks->getElements());
        self::assertCount(2, $subtasks, 'a blank seed text is skipped');
        self::assertSame('step.two', $subtasks[0]->customerProgressStepKey, 'a subtask without a step key inherits its parent\'s');
        self::assertSame('step.contact', $subtasks[1]->customerProgressStepKey, 'an own key wins');
        self::assertSame([], $subtasks[0]->skillNames, 'skill names are never inherited at parse time');
    }

    // --- the repository write and read ------------------------------------------------------------------------------------

    public function testTheRepositoryReadRoundTripsEveryKeyOfAFullyPopulatedItem(): void
    {
        $fullyPopulatedItem = $this->seededLeafWithEveryDeclaration();
        $fullyPopulatedItem->key = '1';
        $fullyPopulatedItem->status = TaskPlanItem::STATUS_BLOCKED;
        $fullyPopulatedItem->statusNote = 'deferred by the owner';
        $fullyPopulatedItem->requiresUserAction = true;
        $fullyPopulatedItem->retryable = true;
        $fullyPopulatedItem->evidenceMessageIndex = 14;
        $fullyPopulatedItem->offerRenderedAtMessageIndex = 9;
        $fullyPopulatedItem->preResolvedAtSeed = true;
        $fullyPopulatedItem->platformResolvedAtMessageIndex = 12;
        $fullyPopulatedItem->subtasks = $this->itemSet([$this->item('child')]);
        $fullyPopulatedItem->highestSubtaskKeyNumber = 1;

        $persistedFields = $fullyPopulatedItem->toRepositoryFields();
        self::assertSame($persistedFields, TaskPlanItem::fromRepositoryFields($persistedFields)->toRepositoryFields());
        $defaultFields = TaskPlanItem::newInstance()->toRepositoryFields();
        $unsetKeys = [];
        foreach (self::MODULE_PERSISTED_ITEM_KEYS as $persistedKey) {
            if ($defaultFields[$persistedKey] === $persistedFields[$persistedKey]) {
                $unsetKeys[] = $persistedKey;
            }
        }
        self::assertSame(['doneRequiresConnectedChannel'], $unsetKeys, 'the fixture sets every key the module item can hold');
    }

    public function testTheRepositoryReadQuirksOnLegacyAndMalformedValues(): void
    {
        $legacyItem = $this->legacyKeysItem();
        self::assertSame(' Old item ', $legacyItem->text, 'the text is NOT trimmed on read');
        self::assertSame('connection expired', $legacyItem->statusNote, 'the legacy blockReason key is read');
        self::assertTrue($legacyItem->requiresUserAction);
        self::assertSame(0, $legacyItem->evidenceMessageIndex, 'quirk: a non-numeric evidenceMessageIndex reads as 0 (isset + int cast)');
        self::assertNull($legacyItem->offerRenderedAtMessageIndex, 'quirk: a non-numeric offerRenderedAtMessageIndex reads as null (is_numeric)');
        self::assertSame(5, $legacyItem->platformResolvedAtMessageIndex);
        self::assertSame([' Skill.Old ', '7'], $legacyItem->skillNames, 'quirk: the read does NOT trim skill names (the seed parse does)');
        self::assertNull($legacyItem->minReasoningEffort);
        self::assertNull($legacyItem->doneRequiresConnectedChannel);
        self::assertNull($legacyItem->doneRequiresToolCallArgumentsAbsent, 'an empty argument filter reads as null');
        self::assertNull($legacyItem->subtasks, 'an empty subtask level reads as a leaf (null)');
        self::assertTrue($legacyItem->seededByPurpose, 'without the stored flag: seeded, as it carries a declaration (its skill names)');
        self::assertFalse(TaskPlanItem::fromRepositoryFields(['text' => 'x'])->seededByPurpose);
        self::assertTrue(TaskPlanItem::fromRepositoryFields(['text' => 'x', 'doneRequiresWidget' => 'HandoverWidget'])->seededByPurpose);

        self::assertSame('n', TaskPlanItem::fromRepositoryFields(['text' => 'x', 'status' => 'done', 'completionNote' => 'n'])->statusNote, 'the legacy completionNote key is read');
        self::assertSame('a', TaskPlanItem::fromRepositoryFields(['text' => 'x', 'statusNote' => 'a', 'completionNote' => 'b', 'blockReason' => 'c'])->statusNote, 'statusNote wins over both legacy keys');
        self::assertSame(TaskPlanItem::STATUS_OPEN, TaskPlanItem::fromRepositoryFields(['text' => 'x', 'status' => 'finished'])->status, 'an unknown status reads as open');
        self::assertSame(TaskPlanItem::STATUS_NOTE_MAX_LENGTH, mb_strlen((string)TaskPlanItem::fromRepositoryFields(['text' => 'x', 'statusNote' => str_repeat('ü', 250)])->statusNote));
        self::assertNull(TaskPlanItem::fromRepositoryFields(['text' => 'x', 'doneCriterion' => 'entry.confirmed', 'doneCriterionNeverGates' => true])->doneCriterion, 'a criterion stored as never gating is no criterion');
        self::assertSame(0, TaskPlanItem::fromRepositoryFields(['text' => 'x', 'highestSubtaskKeyNumber' => -4])->highestSubtaskKeyNumber);
        self::assertSame(' entry.confirmed ', TaskPlanItem::fromRepositoryFields(['text' => 'x', 'doneCriterion' => ' entry.confirmed '])->doneCriterion, 'the stored criterion is taken as stored, not trimmed');
    }

    // --- the persisted bytes and the identity keys -----------------------------------------------------------------------

    public function testTheCompactPersistedBytesAndTheIdentityKeysOfEachRepresentativeItem(): void
    {
        $actualIdentitiesByItemName = [];
        foreach ($this->goldenItems() as $goldenName => $goldenItem) {
            $goldenPlan = $this->taskPlan([$goldenItem]);
            $goldenPlan->createdAtMessageIndex = 4;
            $goldenPlan->completedAtMessageIndex = 40;
            $actualIdentitiesByItemName[$goldenName] = [
                'compactBytesMd5' => md5((string)json_encode($goldenPlan->items->toRepositoryArray(), self::PERSISTED_JSON_FLAGS)),
                'itemUniqueKey' => $goldenItem->uniqueKey(),
                'planUniqueKey' => $goldenPlan->uniqueKey(),
            ];
        }
        self::assertSame(self::IDENTITIES_BY_ITEM_NAME, $actualIdentitiesByItemName);
    }

    /**
     * The hash of each item's compact persisted bytes, its item key (the class-prefixed key of a minted item, the
     * class-prefixed text of one built without a key) and the plan key (hashed over the DEFAULT json_encode: equal to the
     * bytes hash for the two pure-ASCII items, different for the two with a slash or a non-ASCII character).
     */
    protected const array IDENTITIES_BY_ITEM_NAME = [
        'seeded leaf with every declaration' => ['compactBytesMd5' => '2504ba715ac8a1e577a7db3944e325ee', 'itemUniqueKey' => TaskPlanItem::class . '_key:1', 'planUniqueKey' => 'plan:4:40:ee9afbfec6dd8ebd6a6e813a47075b37'],
        'container with subtasks' => ['compactBytesMd5' => 'e6080598177a6af7281fd33ed337358a', 'itemUniqueKey' => TaskPlanItem::class . '_key:1', 'planUniqueKey' => 'plan:4:40:ccbf38f56dd6162ee9e2ae9c273b2111'],
        'platform-closed leaf with records' => ['compactBytesMd5' => 'c7939e329c4d888909bb4bb66ac1d51c', 'itemUniqueKey' => TaskPlanItem::class . '_Rivals saved', 'planUniqueKey' => 'plan:4:40:c7939e329c4d888909bb4bb66ac1d51c'],
        'legacy keys item' => ['compactBytesMd5' => 'fed438b4a1e8a779f423dffc0101d182', 'itemUniqueKey' => TaskPlanItem::class . '_ Old item ', 'planUniqueKey' => 'plan:4:40:fed438b4a1e8a779f423dffc0101d182'],
    ];

    public function testThePlanUniqueKeyHashesTheItemsWithoutThePersistFlags(): void
    {
        // quirk: TaskPlan::uniqueKey() json_encodes WITHOUT JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE — on an item
        // with a slash or a non-ASCII character its hash is not the hash of the persisted bytes
        $goldenPlan = $this->taskPlan([$this->seededLeafWithEveryDeclaration()]);
        $hashOfPersistedBytes = md5((string)json_encode($goldenPlan->items->toRepositoryArray(), self::PERSISTED_JSON_FLAGS));
        $hashOfDefaultEncoding = md5((string)json_encode($goldenPlan->items->toRepositoryArray()));

        self::assertNotSame($hashOfPersistedBytes, $hashOfDefaultEncoding);
        self::assertSame('plan:x:x:' . $hashOfDefaultEncoding, $goldenPlan->uniqueKey());
    }

    public function testThePersistedPlanSliceCarriesTheTenPlanKeysAroundTheItems(): void
    {
        $taskPlan = new TaskPlan();
        $taskPlan->items = $this->itemSet([$this->containerWithSubtasks()]);
        $planSlice = $taskPlan->mapToRepository();

        self::assertSame(
            ['items', 'updatedAtTurnInitiatorMessageIndex', 'resolvedAtTurnInitiatorMessageIndex', 'title', 'createdAtMessageIndex', 'completedAtMessageIndex', 'completedAt', 'outcomeSummary', 'platformClosedAtTurnEndNote', 'highestItemKeyNumber'],
            array_keys($planSlice)
        );
        self::assertSame($taskPlan->items->toRepositoryArray(), $planSlice['items']);
    }
}
