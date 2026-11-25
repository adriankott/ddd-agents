<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\Conversations\Entitlements;

use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentProductAwareness;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentAccountRightsInterface;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentComposedAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentRoleMappedAccountRights;
use DDD\Domain\AI\Entities\Conversations\Entitlements\AgentFeatureFlagsInterface;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Services\AuthService;
use DDD\Modules\Agents\AgentsModule;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

/**
 * The two entitlement axes the agent core gates skills, tools and purposes by — the feature flags of the space and the
 * rights of the acting account — and what the model is told about the product. The flag and right names are opaque
 * strings only the application interprets.
 *
 * The defaults: no flags (null, ungated), the account's rights by the role mapping `AI.accountRights`, an empty
 * product awareness, texts unchanged. An
 * application puts a same-path subclass under its own root namespace; the core resolves this service only through
 * `DDDService::instance()->getService(AIConversationEntitlementsService::class)`.
 */
class AIConversationEntitlementsService extends Service
{
    /**
     * The feature flags that hold for the conversation (those of its space, or of its account without one). The default: null (ungated).
     *
     * @param AIConversation $conversation
     * @return AgentFeatureFlagsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findFeatureFlagsOfConversation(AIConversation $conversation): ?AgentFeatureFlagsInterface
    {
        return null;
    }

    /**
     * The feature flags of `$agentSpace`, asked where no conversation is at hand (a tool call that targets a space,
     * a purposed start). The default: null (ungated).
     *
     * @param AgentSpace $agentSpace
     * @return AgentFeatureFlagsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findFeatureFlagsOfAgentSpace(AgentSpace $agentSpace): ?AgentFeatureFlagsInterface
    {
        return null;
    }

    /**
     * The feature flags that hold for work of `$account` WITHOUT a space (account level), asked where no conversation
     * is at hand. The default: null (ungated). A caller that gates a tool on a flag refuses when this answers null:
     * a gate that cannot be checked is never passed.
     *
     * @param Account $account
     * @return AgentFeatureFlagsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findFeatureFlagsOfAccount(Account $account): ?AgentFeatureFlagsInterface
    {
        return null;
    }

    /**
     * The rights of the account the conversation acts for — the application's override point. The default: the rights
     * the config `AI.accountRights` maps to the account's roles ({@see AgentRoleMappedAccountRights}); null (ungated)
     * for a conversation without an account. An application returns its own {@see AgentAccountRightsInterface}; the
     * package never reads this method directly but composes its answer with the application's own mapping
     * ({@see findComposedAccountRightsOfConversation()}): package rights the application does not know follow the
     * application's `AI.accountRights`; without an application mapping they are not held.
     *
     * @param AIConversation $conversation
     * @return AgentAccountRightsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAccountRightsOfConversation(AIConversation $conversation): ?AgentAccountRightsInterface
    {
        $account = $this->findAccountOfConversation($conversation);
        return $account instanceof Account ? AgentRoleMappedAccountRights::forAccount($account) : null;
    }

    /**
     * The rights the package CHECKS for the conversation: {@see findAccountRightsOfConversation()} — the application's
     * override point — composed with the mapping `AI.accountRights` ({@see composeAccountRights()}), so the package
     * rights an application object does not answer still follow the mapping. Every gate of the package reads this one.
     *
     * @param AIConversation $conversation
     * @return AgentAccountRightsInterface|null null = ungated (the application answered null)
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findComposedAccountRightsOfConversation(AIConversation $conversation): ?AgentAccountRightsInterface
    {
        $accountRights = $this->findAccountRightsOfConversation($conversation);
        if ($accountRights === null) {
            return null;
        }
        return $this->composeAccountRights($accountRights, $this->findAccountOfConversation($conversation));
    }

    /**
     * The rights the package CHECKS for the authenticated account: {@see findAccountRightsOfCurrentAccount()} composed
     * with the mapping ({@see composeAccountRights()}).
     *
     * @return AgentAccountRightsInterface|null null = ungated
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findComposedAccountRightsOfCurrentAccount(): ?AgentAccountRightsInterface
    {
        $accountRights = $this->findAccountRightsOfCurrentAccount();
        if ($accountRights === null) {
            return null;
        }
        return $this->composeAccountRights($accountRights, AuthService::instance()->getAccount());
    }

    /** @var string The mapping file of `AI.accountRights`, relative to a config root */
    public const string ACCOUNT_RIGHTS_MAPPING_FILE = 'AI/accountRights.php';

    /**
     * `$accountRights` as the package checks them: the package's own objects ({@see AgentRoleMappedAccountRights},
     * {@see AgentComposedAccountRights}) as they are; an application's object composed with the mapping of `$account`
     * (its roles; without an account only `'*'` and delegations hold) — {@see AgentComposedAccountRights} — but only
     * when the APPLICATION ships the mapping ({@see isAccountRightsMappingShippedByTheApplication()}). Without one the
     * application's object is answered as it is: a package right it does not hold is not held (fail closed). The
     * package's default mapping never grants a right to an application that answers with its own object.
     *
     * @param AgentAccountRightsInterface $accountRights
     * @param Account|null $account
     * @return AgentAccountRightsInterface
     */
    public function composeAccountRights(AgentAccountRightsInterface $accountRights, ?Account $account): AgentAccountRightsInterface
    {
        if ($accountRights instanceof AgentRoleMappedAccountRights || $accountRights instanceof AgentComposedAccountRights) {
            return $accountRights;
        }
        if (!$this->isAccountRightsMappingShippedByTheApplication()) {
            return $accountRights;
        }
        $agentRoleMappedAccountRights = $account instanceof Account
            ? AgentRoleMappedAccountRights::forAccount($account)
            : AgentRoleMappedAccountRights::forRoleNamesUnderConfiguredMapping([]);
        return AgentComposedAccountRights::compose($accountRights, $agentRoleMappedAccountRights);
    }

    /**
     * Whether the mapping `AI.accountRights` that is read comes from the application (its own
     * `config/app/AI/accountRights.php`, in an application config root or any root other than this package's), not
     * from the package's default file. Overridable for a host that keeps its mapping elsewhere.
     */
    public function isAccountRightsMappingShippedByTheApplication(): bool
    {
        $packageConfigRoot = realpath((string)AgentsModule::getConfigPath());
        foreach (Config::getConfigRootDirectories() as $configRootDirectory) {
            if (is_file($configRootDirectory . '/' . static::ACCOUNT_RIGHTS_MAPPING_FILE)) {
                return realpath($configRootDirectory) !== $packageConfigRoot;
            }
        }
        return false;
    }

    /**
     * Whether the application answers rights with an object of its own: a subclass of this service that overrides
     * {@see findAccountRightsOfConversation()} or {@see findAccountRightsOfCurrentAccount()}.
     */
    public function isAccountRightsAnsweredByTheApplication(): bool
    {
        foreach (['findAccountRightsOfConversation', 'findAccountRightsOfCurrentAccount'] as $methodName) {
            if ((new \ReflectionMethod($this, $methodName))->getDeclaringClass()->getName() !== self::class) {
                return true;
            }
        }
        return false;
    }

    /**
     * The account the conversation acts for: its account as loaded, else the signed-in account when it is the same
     * one, else its account read now; null for a conversation without an account.
     *
     * @param AIConversation $conversation
     * @return Account|null
     */
    protected function findAccountOfConversation(AIConversation $conversation): ?Account
    {
        $conversationAccountId = $conversation->accountId ?? null;
        if ($conversationAccountId === null) {
            return null;
        }
        $account = isset($conversation->account) ? $conversation->account : null;
        $currentAccount = AuthService::instance()->getAccount();
        if ($account === null && $currentAccount !== null && (int)$currentAccount->id === (int)$conversationAccountId) {
            $account = $currentAccount;
        }
        if ($account === null) {
            $account = $conversation->account ?? null;
        }
        return $account instanceof Account ? $account : null;
    }

    /**
     * The rights of the authenticated account of the current request. The default: the rights the config
     * `AI.accountRights` maps to its roles ({@see AgentRoleMappedAccountRights}); null (ungated) without an account.
     *
     * @return AgentAccountRightsInterface|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAccountRightsOfCurrentAccount(): ?AgentAccountRightsInterface
    {
        $account = AuthService::instance()->getAccount();
        return $account !== null ? AgentRoleMappedAccountRights::forAccount($account) : null;
    }

    /**
     * Whether the model may be told the exact AI budget figures of the conversation. The default: true.
     *
     * @param AIConversation $conversation
     * @return bool
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function isExactAIBudgetReportable(AIConversation $conversation): bool
    {
        return true;
    }

    /**
     * Whether the conversation's account may see and change billing (a skill or purpose that requires billing access is
     * offered only then). The default: null (unknown — the account model of the framework carries no billing right).
     *
     * @param AIConversation $conversation
     * @return bool|null
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function findAccountBillingAccessOfConversation(AIConversation $conversation): ?bool
    {
        return null;
    }

    /**
     * What the model is told about the customer's product (capabilities, restricted capabilities, AI budget policy). The
     * default: an empty awareness (every segment omitted).
     *
     * @param AIConversation $conversation
     * @return AgentProductAwareness
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function buildProductAwareness(AIConversation $conversation): AgentProductAwareness
    {
        return new AgentProductAwareness();
    }

    /**
     * `$text` with the product names replaced by the names the conversation's owner knows them by. The default returns
     * the text unchanged.
     *
     * @param AIConversation $conversation
     * @param string $text
     * @return string
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function localizeProductNamesInText(AIConversation $conversation, string $text): string
    {
        return $text;
    }
}
