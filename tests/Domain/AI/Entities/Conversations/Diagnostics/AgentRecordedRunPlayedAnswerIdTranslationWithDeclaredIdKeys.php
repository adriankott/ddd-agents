<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Diagnostics;

use DDD\Domain\AI\Entities\Conversations\Diagnostics\AgentRecordedRunPlayedAnswerIdTranslation;

/** The translation of an application that declares the tables of the id keys its own tools take. */
class AgentRecordedRunPlayedAnswerIdTranslationWithDeclaredIdKeys extends AgentRecordedRunPlayedAnswerIdTranslation
{
    public const array TABLE_BY_ID_KEY = [
        'keywordId' => 'keywords', 'keywordIds' => 'keywords',
        'competitorId' => 'site_competitors', 'competitorIds' => 'site_competitors',
    ];
}
