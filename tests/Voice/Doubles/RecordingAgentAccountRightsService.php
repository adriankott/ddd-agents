<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Voice\Doubles;

use DDD\Domain\AI\Services\Conversations\Rights\AgentAccountRightsService;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/** The package's rights service, recording each question of the account read rights and answering {@see $answer}. */
class RecordingAgentAccountRightsService extends AgentAccountRightsService
{
    /** @var array<int, array{queryBuilder: DoctrineQueryBuilder, baseModelAlias: string, conversationRelation: ?string, accountIdProperty: string}> */
    public array $recordedQuestions = [];

    public bool $answer = true;

    public function applyAccountReadRights(
        DoctrineQueryBuilder &$queryBuilder,
        string $baseModelAlias,
        ?string $conversationRelation = null,
        string $accountIdProperty = 'accountId'
    ): bool {
        $this->recordedQuestions[] = [
            'queryBuilder' => $queryBuilder,
            'baseModelAlias' => $baseModelAlias,
            'conversationRelation' => $conversationRelation,
            'accountIdProperty' => $accountIdProperty,
        ];
        return $this->answer;
    }
}
