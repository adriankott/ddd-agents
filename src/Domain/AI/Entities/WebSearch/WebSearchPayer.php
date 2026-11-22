<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\WebSearch;

use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;

/**
 * Who pays for a web search and its page readings: an account and, optionally, an entity within it the costs are
 * attributed to (a project, a location, a world). Resolved by the application
 * ({@see \DDD\Domain\AI\Services\WebSearch\WebSearchHostService::findPayer()}); the readings are billed through the
 * language-model trait's {@see \DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait::billAICostsTo()}, the search
 * fee through the application's booking hook. Without an account nothing can be billed, so no page is read by a model.
 */
class WebSearchPayer extends ValueObject
{
    /** The paying account; null when the call has none (the HTTP path without authentication). */
    #[HideProperty]
    public ?Account $account = null;

    /** The entity within the account the costs are attributed to; null for the account itself. */
    #[HideProperty]
    public ?Entity $entityWithinAccount = null;

    /** A payer of `$account`, optionally attributed to `$entityWithinAccount`. */
    public static function forAccount(?Account $account, ?Entity $entityWithinAccount = null): static
    {
        $webSearchPayer = static::newInstance();
        $webSearchPayer->account = $account;
        $webSearchPayer->entityWithinAccount = $entityWithinAccount;
        return $webSearchPayer;
    }

    /** Whether there is an account to bill. */
    public function hasAccount(): bool
    {
        return $this->account !== null;
    }
}
