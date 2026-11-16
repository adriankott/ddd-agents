<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals\ConversationPurge;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The first purged trees of one run of the evaluation purge ({@see AIConversationEvalPurgeSampleRoot}), in the order
 * they were purged.
 *
 * @property AIConversationEvalPurgeSampleRoot[] $elements
 * @method AIConversationEvalPurgeSampleRoot[] getElements()
 * @method AIConversationEvalPurgeSampleRoot|null first()
 * @method AIConversationEvalPurgeSampleRoot|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AIConversationEvalPurgeSampleRoots extends ObjectSet
{
}
