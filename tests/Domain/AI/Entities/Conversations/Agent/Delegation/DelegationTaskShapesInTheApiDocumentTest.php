<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Delegation;

use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAgentTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationAwaitedRunTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationTask;
use DDD\Domain\AI\Entities\Conversations\Agent\Delegation\AgentDelegationToolRunTask;
use DDD\Domain\AI\Entities\Conversations\Messages\Delegation\DelegationSubAgentRecord;
use DDD\Infrastructure\Reflection\ClassWithNamespace;
use DDD\Presentation\Base\OpenApi\Attributes\Parameter;
use DDD\Presentation\Base\OpenApi\Components\Schema;
use DDD\Presentation\Base\OpenApi\Document;
use DDD\Tests\Agents\Support\UnitTestCase;
use Symfony\Component\Routing\RouteCollection;

/**
 * A sub-agent record's task is documented as each of its three shapes (`oneOf` of the agent task, the tool run and the
 * awaited run), never as the abstract base alone, and a stored record still reads back as its concrete task.
 */
class DelegationTaskShapesInTheApiDocumentTest extends UnitTestCase
{
    public function testTheTaskIsDocumentedAsItsThreeConcreteShapes(): void
    {
        $routeCollection = new RouteCollection();
        new Document($routeCollection, maxRecursiveSchemaDepth: 2);
        $recordClass = new ClassWithNamespace(DelegationSubAgentRecord::class);
        $schema = new Schema($recordClass, Parameter::RESPONSE);
        $schema->buildSchema();

        $taskJson = (string)json_encode($schema->properties['task'] ?? null);
        foreach ([AgentDelegationAgentTask::class, AgentDelegationToolRunTask::class, AgentDelegationAwaitedRunTask::class] as $taskClass) {
            self::assertStringContainsString(str_replace('\\', '.', $taskClass), $taskJson);
        }
        self::assertStringContainsString('oneOf', $taskJson);
        self::assertStringNotContainsString(str_replace('\\', '.', AgentDelegationTask::class) . '"', $taskJson, 'never the abstract base');
    }

    public function testAStoredRecordReadsBackItsConcreteTask(): void
    {
        $agentDelegationToolRunTask = new AgentDelegationToolRunTask();
        $agentDelegationToolRunTask->toolName = 'listImageModels';
        $stored = DelegationSubAgentRecord::legacy($agentDelegationToolRunTask, 7, "done", null)->mapToRepository();

        $readBack = DelegationSubAgentRecord::fromStoredArray($stored);

        self::assertInstanceOf(AgentDelegationToolRunTask::class, $readBack->task);
    }
}
