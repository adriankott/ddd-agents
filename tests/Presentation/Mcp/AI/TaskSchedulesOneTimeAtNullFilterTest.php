<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Presentation\Mcp\AI;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinition;
use DDD\Domain\Base\Entities\QueryOptions\FiltersDefinitions;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Infrastructure\Libs\Config;
use DDD\Presentation\Mcp\Client\Agents\Dtos\TaskSchedulesGetRequestDto;
use DDD\Tests\Agents\Support\UnitTestCase;
use DateTimeZone;

/**
 * The `getTaskSchedules` idiom for recurring vs one-time tasks — `oneTimeAt eq null` / `ne null`, also written as the
 * quoted `'NULL'` — is accepted under a model-facing time zone: `oneTimeAt` is a MOMENT, and the zone conversion of
 * its literals leaves a null comparison alone (mgamadeus/ddd 3.46.3; before, `oneTimeAt eq 'NULL'` was refused as not a
 * date-time).
 */
class TaskSchedulesOneTimeAtNullFilterTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (Config::getEnv('LAZYLOAD_DEFAULT_REPO_TYPE') === null) {
            LazyLoadRepo::setDafaultRepoType(LazyLoadRepo::DB);
        }
    }

    protected function findFiltersDefinitionsOfGetTaskSchedules(): FiltersDefinitions
    {
        $filtersDefinitions = TaskSchedulesGetRequestDto::getDtoQueryOptions()?->getQueryOptions()?->getFiltersDefinitions();
        self::assertNotNull($filtersDefinitions);
        return $filtersDefinitions;
    }

    public function testOneTimeAtIsAMomentFilter(): void
    {
        $oneTimeAtDefinition = $this->findFiltersDefinitionsOfGetTaskSchedules()->getFilterDefinitionForPropertyName('oneTimeAt');

        self::assertNotNull($oneTimeAtDefinition);
        self::assertSame(FiltersDefinition::TEMPORAL_KIND_MOMENT, $oneTimeAtDefinition->temporalKind);
    }

    /** @return array<string, array{0: string}> */
    public static function recurringAndOneTimeFilters(): array
    {
        return [
            "eq 'NULL'" => ["oneTimeAt eq 'NULL'"],
            "ne 'NULL'" => ["oneTimeAt ne 'NULL'"],
            'eq null' => ['oneTimeAt eq null'],
            'ne null' => ['oneTimeAt ne null'],
            'in a group' => ["status in ['ACTIVE','PAUSED'] and oneTimeAt eq 'NULL'"],
        ];
    }

    /** @dataProvider recurringAndOneTimeFilters */
    public function testTheNullComparisonIsAcceptedUnderAModelFacingZone(string $filterQuery): void
    {
        $filtersDefinitions = $this->findFiltersDefinitionsOfGetTaskSchedules();
        $filtersOptions = FiltersOptions::fromString($filterQuery);

        $filtersOptions->validateAgainstDefinitions($filtersDefinitions);
        $filtersOptions->normalizeMomentLiterals($filtersDefinitions, new DateTimeZone('Europe/Berlin'));

        self::assertStringContainsString('oneTimeAt', $filtersOptions->toCanonicalExpression());
    }
}
