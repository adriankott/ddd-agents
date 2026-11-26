<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Conversations\Memories;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryCurationCall;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryExtraction;
use DDD\Domain\AI\Repo\Argus\Conversations\Memories\ArgusAgentMemoryReconcile;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * An application that measures its curation on several models chooses the model of each call: the chosen model is
 * the one the call runs on and the one its attempt record names.
 */
class ArgusAgentMemoryCurationCallModelChoiceTest extends UnitTestCase
{
    public function testAChosenModelIsTheModelOfTheExtraction(): void
    {
        $this->assertTheChosenModelIsTheCallsModel(ArgusAgentMemoryExtraction::newInstance());
    }

    public function testAChosenModelIsTheModelOfTheReconciliation(): void
    {
        $this->assertTheChosenModelIsTheCallsModel(ArgusAgentMemoryReconcile::newInstance());
    }

    public function testTheAttemptRecordNamesTheChosenModel(): void
    {
        $argusAgentMemoryExtraction = ArgusAgentMemoryExtraction::newInstance();
        $argusAgentMemoryExtraction->useAIModel($this->buildModel('TESTVENDOR.MEASURED_MODEL'));

        self::assertSame('TESTVENDOR.MEASURED_MODEL', $argusAgentMemoryExtraction->buildModelCallOfAttempt(1)->aiModelName);
    }

    public function testALaterChoiceReplacesTheEarlierOne(): void
    {
        $argusAgentMemoryReconcile = ArgusAgentMemoryReconcile::newInstance();
        $argusAgentMemoryReconcile->useAIModel($this->buildModel('TESTVENDOR.FIRST_MODEL'));
        $secondModel = $this->buildModel('TESTVENDOR.SECOND_MODEL');
        $argusAgentMemoryReconcile->useAIModel($secondModel);

        self::assertSame($secondModel, $argusAgentMemoryReconcile->getAIModel());
    }

    /**
     * @param ArgusAgentMemoryCurationCall $argusAgentMemoryCurationCall
     * @return void
     */
    protected function assertTheChosenModelIsTheCallsModel(ArgusAgentMemoryCurationCall $argusAgentMemoryCurationCall): void
    {
        $chosenModel = $this->buildModel('TESTVENDOR.MEASURED_MODEL');
        $argusAgentMemoryCurationCall->useAIModel($chosenModel);

        self::assertSame($chosenModel, $argusAgentMemoryCurationCall->getAIModel());
    }

    /**
     * @param string $name
     * @return AIModel
     */
    protected function buildModel(string $name): AIModel
    {
        $aIModel = new AIModel();
        $aIModel->name = $name;
        return $aIModel;
    }
}
