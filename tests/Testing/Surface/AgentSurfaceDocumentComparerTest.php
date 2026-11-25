<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Testing\Surface;

use DDD\Testing\Agents\Surface\AgentSurfaceDifference;
use DDD\Testing\Agents\Surface\AgentSurfaceDocumentComparer;
use PHPUnit\Framework\TestCase;

/**
 * The comparison of two surface documents value by value: what is added, removed and changed is named by its path;
 * a list is one value; a document restricted to another's entries keeps only those.
 */
class AgentSurfaceDocumentComparerTest extends TestCase
{
    public function testEqualDocumentsHaveNoDifference(): void
    {
        $document = $this->buildDocument();

        self::assertCount(0, (new AgentSurfaceDocumentComparer())->compare($document, $document));
    }

    public function testAChangedValueIsNamedByItsPathWithBothValues(): void
    {
        $comparedDocument = $this->buildDocument();
        $comparedDocument['packages']['TasksPackage']['tools']['getTasks']['description'] = '#bbbbbbbbbbbb (310 characters)';

        $differences = (new AgentSurfaceDocumentComparer())->compare($this->buildDocument(), $comparedDocument)->getElements();

        self::assertCount(1, $differences);
        self::assertSame(AgentSurfaceDifference::KIND_CHANGED, $differences[0]->kind);
        self::assertSame('packages → TasksPackage → tools → getTasks → description', $differences[0]->path);
        self::assertSame('#aaaaaaaaaaaa (300 characters)', $differences[0]->referenceValue);
        self::assertSame('#bbbbbbbbbbbb (310 characters)', $differences[0]->comparedValue);
        self::assertSame(
            '~ packages → TasksPackage → tools → getTasks → description: "#aaaaaaaaaaaa (300 characters)" => "#bbbbbbbbbbbb (310 characters)"',
            $differences[0]->describe()
        );
    }

    public function testARenamedParameterIsOneRemovedAndOneAddedValue(): void
    {
        $comparedDocument = $this->buildDocument();
        $parameters = &$comparedDocument['packages']['TasksPackage']['tools']['getTasks']['parameters'];
        $parameters['locationId'] = $parameters['agentSpaceId'];
        unset($parameters['agentSpaceId']);
        unset($parameters);

        $differences = (new AgentSurfaceDocumentComparer())->compare($this->buildDocument(), $comparedDocument);

        self::assertSame(
            [
                'packages → TasksPackage → tools → getTasks → parameters → agentSpaceId' => AgentSurfaceDocumentComparer::ABSENT,
                'packages → TasksPackage → tools → getTasks → parameters → locationId' => '#cccccccccccc (190 characters)',
            ],
            $differences->toComparedValuesByPath()
        );
        self::assertSame(
            "- packages → TasksPackage → tools → getTasks → parameters → agentSpaceId: \"#cccccccccccc (190 characters)\"\n"
            . '+ packages → TasksPackage → tools → getTasks → parameters → locationId: "#cccccccccccc (190 characters)"',
            $differences->describe()
        );
    }

    public function testAListIsComparedAsOneValueAndItsOrderCounts(): void
    {
        $comparedDocument = $this->buildDocument();
        $comparedDocument['skills']['Agent.Tasks']['toolNames'] = ['endTask', 'getTasks'];

        $differences = (new AgentSurfaceDocumentComparer())->compare($this->buildDocument(), $comparedDocument)->getElements();

        self::assertCount(1, $differences);
        self::assertSame('skills → Agent.Tasks → toolNames', $differences[0]->path);
        self::assertSame(['getTasks', 'endTask'], $differences[0]->referenceValue);
    }

    public function testAnEmptyListAgainstAFilledListIsOneChangedValue(): void
    {
        $referenceDocument = ['skills' => ['Agent.Tasks' => ['toolNames' => []]]];
        $comparedDocument = ['skills' => ['Agent.Tasks' => ['toolNames' => ['getTasks']]]];

        $differences = (new AgentSurfaceDocumentComparer())->compare($referenceDocument, $comparedDocument)->getElements();

        self::assertCount(1, $differences);
        self::assertSame(AgentSurfaceDifference::KIND_CHANGED, $differences[0]->kind);
        self::assertSame([], $differences[0]->referenceValue);
    }

    public function testAnEmptyMapAgainstAFilledMapReportsOnlyTheEntries(): void
    {
        $emptyDocument = ['packages' => ['TasksPackage' => ['tools' => ['getTasks' => ['parameters' => []]]]]];
        $filledDocument = ['packages' => ['TasksPackage' => ['tools' => ['getTasks' => ['parameters' => ['top' => ['type' => 'integer']]]]]]];
        $agentSurfaceDocumentComparer = new AgentSurfaceDocumentComparer();

        self::assertSame(
            ['packages → TasksPackage → tools → getTasks → parameters → top → type' => 'integer'],
            $agentSurfaceDocumentComparer->compare($emptyDocument, $filledDocument)->toComparedValuesByPath()
        );
        self::assertSame(
            ['packages → TasksPackage → tools → getTasks → parameters → top → type' => AgentSurfaceDocumentComparer::ABSENT],
            $agentSurfaceDocumentComparer->compare($filledDocument, $emptyDocument)->toComparedValuesByPath()
        );
    }

    public function testAnEmptyMapOnOneSideOnlyIsAValueOfItsOwn(): void
    {
        $referenceDocument = ['packages' => ['TasksPackage' => ['packageId' => 'agent.tasks']]];
        $comparedDocument = ['packages' => ['TasksPackage' => ['packageId' => 'agent.tasks', 'tools' => []]]];

        $differences = (new AgentSurfaceDocumentComparer())->compare($referenceDocument, $comparedDocument)->getElements();

        self::assertCount(1, $differences);
        self::assertSame(AgentSurfaceDifference::KIND_ADDED, $differences[0]->kind);
        self::assertSame('packages → TasksPackage → tools', $differences[0]->path);
    }

    public function testADocumentRestrictedToAnothersEntriesKeepsOnlyThose(): void
    {
        $applicationDocument = $this->buildDocument();
        $applicationDocument['skills']['Shop.Orders'] = ['isLoadable' => true];
        $applicationDocument['packages']['OrdersPackage'] = ['packageId' => 'shop.orders', 'tools' => []];
        $packageDocument = $this->buildDocument();
        $packageDocument['skills']['Agent.Memory'] = ['isLoadable' => true];
        $agentSurfaceDocumentComparer = new AgentSurfaceDocumentComparer();

        $restrictedDocument = $agentSurfaceDocumentComparer->restrictToEntriesOf($applicationDocument, $packageDocument);

        self::assertSame(['Agent.Tasks'], array_keys($restrictedDocument['skills']));
        self::assertSame(['TasksPackage'], array_keys($restrictedDocument['packages']));
        // the package's skill the application does not offer is reported, the application's own skill is not
        self::assertSame(
            ['skills → Agent.Memory → isLoadable' => AgentSurfaceDocumentComparer::ABSENT],
            $agentSurfaceDocumentComparer->compare($packageDocument, $restrictedDocument)->toComparedValuesByPath()
        );
    }

    public function testADocumentWithoutAnothersEntriesKeepsTheRestAndBothPartsHoldEveryEntryOnce(): void
    {
        $applicationDocument = $this->buildDocument();
        $applicationDocument['skills']['Shop.Orders'] = ['isLoadable' => true];
        $applicationDocument['packages']['OrdersPackage'] = ['packageId' => 'shop.orders', 'tools' => []];
        $applicationDocument['bundles']['App\\ShopMCPBundle'] = ['tools' => ['getOrders' => '#dddddddddddd (90 characters)']];
        $packageDocument = $this->buildDocument();
        $agentSurfaceDocumentComparer = new AgentSurfaceDocumentComparer();

        $ownDocument = $agentSurfaceDocumentComparer->withoutEntriesOf($applicationDocument, $packageDocument);
        $restrictedDocument = $agentSurfaceDocumentComparer->restrictToEntriesOf($applicationDocument, $packageDocument);

        self::assertSame(['Shop.Orders'], array_keys($ownDocument['skills']));
        self::assertSame(['OrdersPackage'], array_keys($ownDocument['packages']));
        self::assertSame(['App\\ShopMCPBundle'], array_keys($ownDocument['bundles']));
        self::assertSame([], $ownDocument['purposes']);
        foreach ($applicationDocument as $partName => $part) {
            self::assertEqualsCanonicalizing(
                array_keys($part),
                array_merge(array_keys($ownDocument[$partName]), array_keys($restrictedDocument[$partName])),
                $partName
            );
        }
    }

    public function testDifferencesToAnothersEntriesNameAMissingEntryOnceAndIgnoreTheApplicationsOwn(): void
    {
        $packageDocument = $this->buildDocument();
        $packageDocument['skills']['Agent.Memory'] = ['isLoadable' => true, 'name' => 'Agent.Memory', 'toolNames' => ['remember', 'recall']];
        $packageDocument['packages']['MemoryPackage'] = ['packageId' => 'agent.memory', 'tools' => ['remember' => ['title' => 'remember']]];
        $applicationDocument = $this->buildDocument();
        $applicationDocument['skills']['Shop.Orders'] = ['isLoadable' => true];
        $applicationDocument['packages']['TasksPackage']['tools']['getTasks']['requiredAccountRightNames'] = ['manageTasks'];
        $applicationDocument['packages']['TasksPackage']['tools']['getTasks']['description'] = '#eeeeeeeeeeee (340 characters)';

        $differencesByPath = (new AgentSurfaceDocumentComparer())->buildDifferencesToEntriesOf($packageDocument, $applicationDocument);

        self::assertSame(
            [
                'skills → Agent.Memory' => AgentSurfaceDocumentComparer::ABSENT,
                'packages → MemoryPackage' => AgentSurfaceDocumentComparer::ABSENT,
                'packages → TasksPackage → tools → getTasks → description' => '#eeeeeeeeeeee (340 characters)',
                'packages → TasksPackage → tools → getTasks → requiredAccountRightNames' => ['manageTasks'],
            ],
            $differencesByPath
        );
    }

    public function testAListPartBelongsToItsOwnDocumentAndIsNeverSetAgainstAnothers(): void
    {
        $packageDocument = ['skillOrder' => ['Agent.Tasks', 'Agent.Memory']] + $this->buildDocument();
        $applicationDocument = ['skillOrder' => ['Shop.Orders', 'Agent.Tasks']] + $this->buildDocument();
        $applicationDocument['skills']['Shop.Orders'] = ['isLoadable' => true];
        $agentSurfaceDocumentComparer = new AgentSurfaceDocumentComparer();

        self::assertSame([], $agentSurfaceDocumentComparer->buildDifferencesToEntriesOf($packageDocument, $applicationDocument));
        self::assertArrayNotHasKey('skillOrder', $agentSurfaceDocumentComparer->restrictToEntriesOf($applicationDocument, $packageDocument));
        $ownDocument = $agentSurfaceDocumentComparer->withoutEntriesOf($applicationDocument, $packageDocument);
        self::assertSame(['Shop.Orders', 'Agent.Tasks'], $ownDocument['skillOrder']);
        self::assertSame(['Shop.Orders'], array_keys($ownDocument['skills']));
        // within one application the order is one pinned value
        $reorderedDocument = ['skillOrder' => ['Agent.Tasks', 'Shop.Orders']] + $applicationDocument;
        self::assertSame(
            ['skillOrder' => ['Agent.Tasks', 'Shop.Orders']],
            $agentSurfaceDocumentComparer->compare($applicationDocument, $reorderedDocument)->toComparedValuesByPath()
        );
    }

    public function testALongListOfDifferencesIsCutWithTheirNumber(): void
    {
        $referenceDocument = ['skills' => []];
        $comparedDocument = ['skills' => []];
        for ($skillNumber = 1; $skillNumber <= 5; $skillNumber++) {
            $comparedDocument['skills']['Skill' . $skillNumber] = ['isLoadable' => true];
        }

        $description = (new AgentSurfaceDocumentComparer())->compare($referenceDocument, $comparedDocument)->describe(3);

        self::assertSame(4, count(explode("\n", $description)));
        self::assertStringEndsWith('... and 2 more', $description);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildDocument(): array
    {
        return [
            'skills' => [
                'Agent.Tasks' => ['isLoadable' => true, 'name' => 'Agent.Tasks', 'toolNames' => ['getTasks', 'endTask'], 'packages' => ['TasksPackage']],
            ],
            'packages' => [
                'TasksPackage' => [
                    'packageId' => 'agent.tasks',
                    'tools' => [
                        'getTasks' => [
                            'description' => '#aaaaaaaaaaaa (300 characters)',
                            'effect' => ['effect' => 'READ'],
                            'parameters' => ['agentSpaceId' => '#cccccccccccc (190 characters)', 'top' => ['type' => 'integer']],
                        ],
                    ],
                ],
            ],
            'purposes' => [],
            'bundles' => [],
        ];
    }
}
