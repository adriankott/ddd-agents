<?php

declare(strict_types=1);

namespace DDD\Presentation\Mcp\Client\AI;

use DDD\Domain\AI\Entities\AgentSpaces\AgentSpace;
use DDD\Domain\AI\Entities\Conversations\Agent\AgentToolCallProvenance;
use DDD\Domain\AI\Entities\Conversations\Memories\AgentMemory;
use DDD\Domain\AI\Entities\MCPPackages\MCPPackage;
use DDD\Domain\AI\Entities\MCPPackages\MCPTool;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolEffect;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolParallelSafe;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolShowsNoActivityLine;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolSummary;
use DDD\Domain\AI\Entities\MCPPackages\MCPToolWritesAgentMemoryOnly;
use DDD\Domain\AI\Services\AgentSpaces\AgentSpacesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoriesService;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Common\Entities\Accounts\Account;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\ForbiddenException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Services\AuthService;
use DDD\Infrastructure\Services\DDDService;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMemoriesMcpResponseDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMemoryForgetRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMemoryListRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMemoryRememberRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMemorySearchRequestDto;
use DDD\Presentation\Mcp\Client\AI\Dtos\AgentMemoryUpdateRequestDto;

/**
 * The tools of the package's memory store: recall (list everything, or search a topic), store, correct and forget a
 * durable memory of the owner. Available only while the memory store is switched on
 * ({@see AgentMemoriesService::isMemoryStoreEnabled()}); otherwise the skill binding leaves the package out and a
 * direct call is refused.
 *
 * The account is the one the call acts for (the owner of the conversation, else the signed-in account), never an
 * argument. The space defaults to the conversation's space. A memory about the owner as a person belongs to the
 * account, a memory about the business to the space.
 */
class AgentMemoriesMCPPackage extends MCPPackage
{
    public static ?string $name = 'agent.memories';

    public static ?string $description = 'Recall, store, correct and forget the durable memories the assistant holds about the owner and the business (directives, facts, solutions). Recall has two tools: listMemories (everything that is stored, for an open question) and searchMemory (the nearest to one topic).';

    /** @var int The default and the largest number of memories `searchMemory` returns */
    public const int DEFAULT_SEARCH_LIMIT = 10;
    public const int MAX_SEARCH_LIMIT = 50;

    /** @var int The default and the largest number of memories `listMemories` returns */
    public const int DEFAULT_LIST_LIMIT = 50;
    public const int MAX_LIST_LIMIT = 200;

    /** @var string The refusal of a direct call while the memory store is switched off */
    public const string MEMORY_STORE_OFF_MESSAGE = 'The memory store is not switched on in this installation: nothing can be remembered or recalled.';

    public static function isAvailable(): bool
    {
        return AgentMemoriesService::isMemoryStoreEnabled();
    }

    /**
     * The stored memories NEAREST to a topic (semantic search; a text match when no embedding provider is set up).
     * Use it for a targeted question about one stored fact and before a write, to get the `#<id>` and to avoid a
     * duplicate. For an open question about everything that is stored use listMemories.
     *
     * @example {"query": "how the owner wants to be addressed"}
     * @example {"query": "what sets the business apart", "memoryType": "FACT"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('one read of the memory table')]
    #[MCPToolSummary('Semantic search of the stored facts, directives and solutions NEAREST to a topic. For a targeted recall and before remember/updateMemory/forget (to get the `#<id>`). For an open recall of everything use listMemories.')]
    public function searchMemory(
        AgentMemorySearchRequestDto $requestDto,
        AgentMemoryStoreService $agentMemoryStoreService,
        AgentToolCallProvenance $callProvenance
    ): AgentMemoriesMcpResponseDto {
        $this->requireMemoryStoreEnabled();
        $actingAccount = $this->requireActingAccount($callProvenance);
        if (trim($requestDto->query) === '') {
            throw new BadRequestException('searchMemory needs a query — for everything that is stored call listMemories.');
        }
        return AgentMemoriesMcpResponseDto::forMemories($agentMemoryStoreService->findSimilarMemories(
            $requestDto->query,
            (int)$actingAccount->id,
            $this->findAgentSpaceIdOfCall($requestDto->agentSpaceId, $callProvenance),
            $this->clampLimit($requestDto->limit, static::DEFAULT_SEARCH_LIMIT, static::MAX_SEARCH_LIMIT),
            $this->normalizeMemoryType($requestDto->memoryType)
        ));
    }

    /**
     * EVERY current memory the assistant holds about the owner and the business, newest first, no query: the account's
     * own and those of the space. Use it for an open question (everything you know or remember, every directive). For
     * one topic use searchMemory.
     *
     * @example {}
     * @example {"memoryType": "DIRECTIVE"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::READ)]
    #[MCPToolParallelSafe('one read of the memory table')]
    #[MCPToolSummary('Lists ALL durable memories held about the owner and the business, newest first, no query. For an open recall (everything you know, every directive). For one topic use searchMemory.')]
    public function listMemories(
        AgentMemoryListRequestDto $requestDto,
        AgentMemoryStoreService $agentMemoryStoreService,
        AgentToolCallProvenance $callProvenance
    ): AgentMemoriesMcpResponseDto {
        $this->requireMemoryStoreEnabled();
        $actingAccount = $this->requireActingAccount($callProvenance);
        return AgentMemoriesMcpResponseDto::forMemories($agentMemoryStoreService->findCurrentMemoriesForScope(
            (int)$actingAccount->id,
            $this->findAgentSpaceIdOfCall($requestDto->agentSpaceId, $callProvenance),
            $this->normalizeMemoryType($requestDto->memoryType),
            $this->clampLimit($requestDto->limit, static::DEFAULT_LIST_LIMIT, static::MAX_LIST_LIMIT)
        ));
    }

    /**
     * Stores ONE durable memory the owner just stated: DIRECTIVE (a lasting rule for how you work for the owner), FACT
     * (a soft fact) or SOLUTION (an approach of the owner worth repeating). Only what has no home in a tool or setting
     * of its own; run searchMemory first to avoid a duplicate. A memory about the owner as a person: `subject` USER.
     *
     * @example {"content": "Check the weather forecast before proposing an outdoor event", "memoryType": "DIRECTIVE"}
     * @example {"content": "The business has been family-run for three generations", "memoryType": "FACT", "subject": "BUSINESS"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolWritesAgentMemoryOnly('stores one memory row')]
    #[MCPToolShowsNoActivityLine('the reply says what was noted')]
    #[MCPToolSummary('Store ONE new durable memory (DIRECTIVE / FACT / SOLUTION) the owner just stated and that has no home in a tool or setting. Run searchMemory first to avoid a duplicate.')]
    public function remember(
        AgentMemoryRememberRequestDto $requestDto,
        AgentMemoryStoreService $agentMemoryStoreService,
        AgentToolCallProvenance $callProvenance
    ): AgentMemoriesMcpResponseDto {
        $this->requireMemoryStoreEnabled();
        $actingAccount = $this->requireActingAccount($callProvenance);
        if (trim($requestDto->content) === '') {
            throw new BadRequestException('remember needs the content of the memory.');
        }
        $agentMemory = AgentMemory::buildStated(
            trim($requestDto->content),
            $this->normalizeMemoryType($requestDto->memoryType),
            $this->normalizeSource($requestDto->source)
        );
        $agentMemory->subject = strtoupper(trim((string)$requestDto->subject)) === AgentMemory::SUBJECT_USER
            ? AgentMemory::SUBJECT_USER
            : AgentMemory::SUBJECT_BUSINESS;
        $agentMemory->stampSourceFrom($callProvenance);
        $agentMemory->anchorToScope(
            (int)$actingAccount->id,
            $agentMemory->subject === AgentMemory::SUBJECT_USER ? null : $this->findAgentSpaceIdOfCall($requestDto->agentSpaceId, $callProvenance)
        );
        $existingDuplicate = $agentMemoryStoreService->findCurrentDuplicateOfContent($agentMemory->accountId, $agentMemory->agentSpaceId, $agentMemory->content);
        if ($existingDuplicate instanceof AgentMemory) {
            return AgentMemoriesMcpResponseDto::forOneMemory($existingDuplicate, 'Already remembered.');
        }
        return AgentMemoriesMcpResponseDto::forOneMemory($agentMemoryStoreService->persistMemory($agentMemory), 'Remembered.');
    }

    /**
     * Corrects a stored memory in place by its id (content and/or type). Use it when a stored fact or directive
     * changed or was wrong; take the `#<id>` from a searchMemory or listMemories line.
     *
     * @example {"memoryId": 42, "content": "The business has been family-run for four generations"}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::WRITE)]
    #[MCPToolWritesAgentMemoryOnly('corrects one memory row')]
    #[MCPToolSummary('Correct a stored memory in place by id (content and/or type). Get the `#<id>` from searchMemory or listMemories first.')]
    public function updateMemory(
        AgentMemoryUpdateRequestDto $requestDto,
        AgentMemoryStoreService $agentMemoryStoreService,
        AgentToolCallProvenance $callProvenance
    ): AgentMemoriesMcpResponseDto {
        $this->requireMemoryStoreEnabled();
        $this->requireActingAccount($callProvenance);
        $agentMemory = $agentMemoryStoreService->find((int)$requestDto->memoryId);
        if (!$agentMemory instanceof AgentMemory || !$agentMemory->isCurrentlyValid()) {
            throw new NotFoundException('No such memory — take the `#<id>` from searchMemory or listMemories.');
        }
        $agentMemory->applyEdit(
            $requestDto->content !== null ? trim($requestDto->content) : null,
            $this->normalizeMemoryType($requestDto->memoryType)
        );
        return AgentMemoriesMcpResponseDto::forOneMemory($agentMemoryStoreService->persistMemory($agentMemory), 'Updated.');
    }

    /**
     * Stops recalling a stored memory by its id: it is closed and kept as history. Use it when the owner says to drop
     * it; take the `#<id>` from a searchMemory or listMemories line.
     *
     * @example {"memoryId": 42}
     */
    #[MCPTool]
    #[MCPToolEffect(MCPToolEffect::DELETE)]
    #[MCPToolWritesAgentMemoryOnly('closes one memory row')]
    #[MCPToolSummary('Stop recalling a stored memory by id (closed, kept as history). Get the `#<id>` from searchMemory or listMemories first.')]
    public function forget(
        AgentMemoryForgetRequestDto $requestDto,
        AgentMemoryStoreService $agentMemoryStoreService,
        AgentToolCallProvenance $callProvenance
    ): AgentMemoriesMcpResponseDto {
        $this->requireMemoryStoreEnabled();
        $this->requireActingAccount($callProvenance);
        $invalidatedMemory = $agentMemoryStoreService->invalidateMemory((int)$requestDto->memoryId);
        if (!$invalidatedMemory instanceof AgentMemory) {
            return AgentMemoriesMcpResponseDto::forNothingToForget();
        }
        return AgentMemoriesMcpResponseDto::forOneMemory($invalidatedMemory, 'Forgotten (kept as history, no longer recalled).');
    }

    /**
     * Refuses the call while the memory store is switched off.
     *
     * @return void
     * @throws BadRequestException
     */
    protected function requireMemoryStoreEnabled(): void
    {
        if (!static::isAvailable()) {
            throw new BadRequestException(static::MEMORY_STORE_OFF_MESSAGE);
        }
    }

    /**
     * The account the call acts for: the owner of the conversation, else the signed-in account.
     *
     * @param AgentToolCallProvenance $callProvenance
     * @return Account
     * @throws ForbiddenException
     */
    protected function requireActingAccount(AgentToolCallProvenance $callProvenance): Account
    {
        $conversation = $callProvenance->conversation;
        $actingAccount = ($conversation !== null ? ($conversation->account ?? null) : null) ?? AuthService::instance()->getAccount();
        if (!$actingAccount instanceof Account || !isset($actingAccount->id)) {
            throw new ForbiddenException('No authenticated account');
        }
        return $actingAccount;
    }

    /**
     * The id of the space the call names (its access checked), else the conversation's space; null at account level.
     *
     * @param int|null $agentSpaceId
     * @param AgentToolCallProvenance $callProvenance
     * @return int|null
     */
    protected function findAgentSpaceIdOfCall(?int $agentSpaceId, AgentToolCallProvenance $callProvenance): ?int
    {
        /** @var AgentSpacesService $agentSpacesService */
        $agentSpacesService = DDDService::instance()->getService(AgentSpacesService::class);
        $agentSpace = $agentSpacesService->findAgentSpaceOfToolCall([AgentSpacesService::TOOL_ARGUMENT_AGENT_SPACE_ID => $agentSpaceId], $callProvenance);
        return $agentSpace instanceof AgentSpace && isset($agentSpace->id) ? (int)$agentSpace->id : null;
    }

    /**
     * A memory type the model wrote, upper case; null for none.
     *
     * @param string|null $memoryType
     * @return string|null
     * @throws BadRequestException For a type that does not exist
     */
    protected function normalizeMemoryType(?string $memoryType): ?string
    {
        $memoryType = $memoryType !== null ? strtoupper(trim($memoryType)) : null;
        if ($memoryType === null || $memoryType === '') {
            return null;
        }
        if (!in_array($memoryType, AgentMemory::MEMORY_TYPES, true)) {
            throw new BadRequestException('memoryType is one of ' . implode(', ', AgentMemory::MEMORY_TYPES) . '.');
        }
        return $memoryType;
    }

    /**
     * The source of a stated memory: AGENT_INFERRED when the model says so, otherwise USER_STATED.
     *
     * @param string|null $source
     * @return string
     */
    protected function normalizeSource(?string $source): string
    {
        return strtoupper(trim((string)$source)) === AgentMemory::SOURCE_AGENT_INFERRED
            ? AgentMemory::SOURCE_AGENT_INFERRED
            : AgentMemory::SOURCE_USER_STATED;
    }

    protected function clampLimit(?int $limit, int $defaultLimit, int $maximumLimit): int
    {
        if ($limit === null || $limit < 1) {
            return $defaultLimit;
        }
        return min($limit, $maximumLimit);
    }
}
