<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages;

use Attribute;

/**
 * Marks an #[MCPTool] method that reads the conversation it is called from: the loop fills the argument
 * {@see self::ARGUMENT_NAME} with the id of the calling conversation before the executor runs the tool
 * ({@see \DDD\Domain\AI\Entities\Conversations\Messages\ToolCall::buildExecutionArguments()}) — always, over any value
 * the call carries — and the agent loop's tool schema does not offer the argument to the model
 * ({@see ToolDefinition::toOpenAiToolSchema()}). The model is not trusted to echo the id of its own conversation back
 * (it wrote `0`, and the tool read no conversation). Only a conversation without an id keeps the value the call carries.
 *
 * Read by tool name through {@see MCPToolAttributeCatalog}, which reads the method of the class an application binds:
 * an application that declares the tool on its own package class repeats the attribute there.
 *
 *   #[MCPTool, MCPToolReceivesCurrentConversationId('searches the conversation that calls it')]
 *   public function searchThisConversation(SearchRequestDto $requestDto): SearchResponseDto {}
 */
#[Attribute(Attribute::TARGET_METHOD)]
readonly class MCPToolReceivesCurrentConversationId
{
    /** The argument the loop fills. */
    public const string ARGUMENT_NAME = 'conversationId';

    public function __construct(public string $reason = '')
    {
    }
}
