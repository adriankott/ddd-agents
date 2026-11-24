<?php

declare(strict_types=1);

namespace DDD\Modules\Agents;

use DDD\Infrastructure\Modules\DDDModule;

/**
 * The agents module: the agentic loop on top of `ddd-ai` (conversations, messages, compaction, the task plan, skills
 * and MCP metadata, purposes and criteria, widgets and signal sources, delegation, the supervisor, run claims).
 *
 * The module shares the `DDD\Domain\AI\` and `DDD\Presentation\` roots with `ddd-ai` and the framework, but never a
 * directory: every class sits below one of the namespaces it owns, and no other package has a file there. Owned
 * namespaces ({@see self::OWNED_NAMESPACES}, declared to Core by {@see getOwnedNamespaces()} and checked by Core's
 * `ModuleNamespacesTestCase` and the package's contract test):
 * - `DDD\Domain\AI\Entities\AgentSpaces\`, `…\Entities\ClientTools\`, `…\Entities\Conversations\`, `…\Entities\Discovery\`, `…\Entities\Drives\`,
 *   `…\Entities\Environment\`,
 *   `…\Entities\Evals\`, `…\Entities\MCPPackages\`, `…\Entities\Mandates\`, `…\Entities\Models\CustomerSelection\`,
 *   `…\Entities\RecordedRuns\`, `…\Entities\Skills\`, `…\Entities\SystemHealth\`, `…\Entities\Texts\Embeddings\`, `…\Entities\WebSearch\`;
 * - `DDD\Domain\AI\Exceptions\Conversations\`, `…\Exceptions\Evals\`, `…\Exceptions\Texts\Embeddings\`;
 * - `DDD\Domain\AI\Interfaces\`, `DDD\Domain\AI\MessageHandlers\`;
 * - `DDD\Domain\AI\Libs\RecordedRuns\`, `DDD\Domain\AI\Listeners\Conversations\`,
 *   `DDD\Domain\AI\Messenger\Conversations\`, `DDD\Domain\AI\Traits\Conversations\`;
 * - `DDD\Domain\AI\Repo\Argus\Conversations\`, `…\Repo\Argus\Guard\`, `…\Repo\Argus\Mandates\`, `…\Repo\Argus\WebSearch\`,
 *   `…\Repo\DB\AgentSpaces\`, `…\Repo\DB\ClientTools\`, `…\Repo\DB\Conversations\`, `…\Repo\DB\Evals\`, `…\Repo\DB\Mandates\`;
 * - `DDD\Domain\AI\Services\AgentSpaces\`, `…\Services\ClientTools\`, `…\Services\Conversations\`, `…\Services\Decisions\`, `…\Services\Drives\`,
 *   `…\Services\Eval\`, `…\Services\Mandates\`,
 *   `…\Services\Skills\`, `…\Services\SystemHealth\`, `…\Services\SystemPrompts\`, `…\Services\Texts\Embeddings\`, `…\Services\WebSearch\`;
 * - `DDD\Modules\Agents\`, `DDD\Presentation\Api\Agents\`, `DDD\Presentation\Mcp\` (the MCP / JSON-RPC wire DTOs,
 *   the tool executor, the console printer and the JSON-RPC base controller);
 * - `DDD\Symfony\Commands\Agents\` (the console commands of the module, their shared base, the option traits and the
 *   progress reporter).
 *
 * Every `Services` namespace of the module is public ({@see getPublicServiceNamespaces()}). What is no service lives
 * outside them and stays private: the recorded-run clock and id sources (a static library, `Libs\RecordedRuns`), the
 * listener interfaces and the transaction listener (`Listeners\Conversations`), the worker state resetter
 * (`Messenger\Conversations`, reached through its tag), the console stream (`Presentation\Mcp\Agents`, built with
 * `new`), the trace log trait (`Traits\Conversations`) and the console commands (`Symfony\Commands\Agents`, reached
 * through the console tag their `#[AsCommand]` attribute gives them; an application command of the same name
 * replaces one, as for every command below `DDD\Symfony\Commands\`).
 *
 * An application overrides a class by a same-path subclass under its own root namespace.
 */
class AgentsModule extends DDDModule
{
    /** @var string[] The namespaces this module owns (see the class docblock); each ends with a backslash. */
    public const array OWNED_NAMESPACES = [
        'DDD\\Domain\\AI\\Entities\\AgentSpaces\\',
        'DDD\\Domain\\AI\\Entities\\ClientTools\\',
        'DDD\\Domain\\AI\\Entities\\Conversations\\',
        'DDD\\Domain\\AI\\Entities\\Discovery\\',
        'DDD\\Domain\\AI\\Entities\\Drives\\',
        'DDD\\Domain\\AI\\Entities\\Environment\\',
        'DDD\\Domain\\AI\\Entities\\Evals\\',
        'DDD\\Domain\\AI\\Entities\\MCPPackages\\',
        'DDD\\Domain\\AI\\Entities\\Mandates\\',
        'DDD\\Domain\\AI\\Entities\\Models\\CustomerSelection\\',
        'DDD\\Domain\\AI\\Entities\\RecordedRuns\\',
        'DDD\\Domain\\AI\\Entities\\Skills\\',
        'DDD\\Domain\\AI\\Entities\\SystemHealth\\',
        'DDD\\Domain\\AI\\Entities\\Texts\\Embeddings\\',
        'DDD\\Domain\\AI\\Entities\\WebSearch\\',
        'DDD\\Domain\\AI\\Exceptions\\Conversations\\',
        'DDD\\Domain\\AI\\Exceptions\\Evals\\',
        'DDD\\Domain\\AI\\Exceptions\\Texts\\Embeddings\\',
        'DDD\\Domain\\AI\\Interfaces\\',
        'DDD\\Domain\\AI\\Libs\\RecordedRuns\\',
        'DDD\\Domain\\AI\\Listeners\\Conversations\\',
        'DDD\\Domain\\AI\\MessageHandlers\\',
        'DDD\\Domain\\AI\\Messenger\\Conversations\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\Conversations\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\Guard\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\Mandates\\',
        'DDD\\Domain\\AI\\Repo\\Argus\\WebSearch\\',
        'DDD\\Domain\\AI\\Repo\\DB\\AgentSpaces\\',
        'DDD\\Domain\\AI\\Repo\\DB\\ClientTools\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Conversations\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Evals\\',
        'DDD\\Domain\\AI\\Repo\\DB\\Mandates\\',
        'DDD\\Domain\\AI\\Services\\AgentSpaces\\',
        'DDD\\Domain\\AI\\Services\\ClientTools\\',
        'DDD\\Domain\\AI\\Services\\Conversations\\',
        'DDD\\Domain\\AI\\Services\\Decisions\\',
        'DDD\\Domain\\AI\\Services\\Drives\\',
        'DDD\\Domain\\AI\\Services\\Eval\\',
        'DDD\\Domain\\AI\\Services\\Mandates\\',
        'DDD\\Domain\\AI\\Services\\Skills\\',
        'DDD\\Domain\\AI\\Services\\SystemPrompts\\',
        'DDD\\Domain\\AI\\Services\\SystemHealth\\',
        'DDD\\Domain\\AI\\Services\\Texts\\Embeddings\\',
        'DDD\\Domain\\AI\\Services\\WebSearch\\',
        'DDD\\Domain\\AI\\Traits\\Conversations\\',
        'DDD\\Modules\\Agents\\',
        'DDD\\Presentation\\Api\\Agents\\',
        'DDD\\Presentation\\Mcp\\',
        'DDD\\Symfony\\Commands\\Agents\\',
    ];

    /** @return string[] {@see self::OWNED_NAMESPACES}: Core checks that no other package ships a class below them */
    public static function getOwnedNamespaces(): array
    {
        return static::OWNED_NAMESPACES;
    }

    public static function getSourcePath(): string
    {
        return __DIR__ . '/../..';
    }

    public static function getConfigPath(): ?string
    {
        return __DIR__ . '/../../../config/app';
    }

    /** Only namespaces the module owns ({@see self::OWNED_NAMESPACES}); every one of its `Services` namespaces. */
    public static function getPublicServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\AI\\Services\\AgentSpaces\\',
            'DDD\\Domain\\AI\\Services\\Conversations\\',
            'DDD\\Domain\\AI\\Services\\Decisions\\',
            'DDD\\Domain\\AI\\Services\\Skills\\',
            'DDD\\Domain\\AI\\Services\\SystemPrompts\\',
            // the mandates, the eval outbound guard and the embedding port (security gates and a port the core calls)
            'DDD\\Domain\\AI\\Services\\Mandates\\',
            'DDD\\Domain\\AI\\Services\\Eval\\',
            'DDD\\Domain\\AI\\Services\\Texts\\Embeddings\\',
            // the web research and its host hooks (the tools and the application fetch them with getService())
            'DDD\\Domain\\AI\\Services\\WebSearch\\',
            // the drive stores' registry (the drive tools and the file card fetch it)
            'DDD\\Domain\\AI\\Services\\Drives\\',
            // the client tool request channel of Agent.UI (the tools, the chat routes and the purge command fetch it)
            'DDD\\Domain\\AI\\Services\\ClientTools\\',
            // the health report of System.Health and its sections port
            'DDD\\Domain\\AI\\Services\\SystemHealth\\',
            'DDD\\Presentation\\Mcp\\Services\\',
        ];
    }

    /**
     * The service namespaces the container builds lazily (a proxy until first use) — every public service namespace
     * except the presentation services: the services the loop resolves through `getService()` and that pull large
     * graphs of further services.
     */
    public static function getLazyServiceNamespaces(): array
    {
        return [
            'DDD\\Domain\\AI\\Services\\AgentSpaces\\',
            'DDD\\Domain\\AI\\Services\\Conversations\\',
            'DDD\\Domain\\AI\\Services\\Decisions\\',
            'DDD\\Domain\\AI\\Services\\Skills\\',
            'DDD\\Domain\\AI\\Services\\SystemPrompts\\',
            'DDD\\Domain\\AI\\Services\\Mandates\\',
            'DDD\\Domain\\AI\\Services\\Eval\\',
            'DDD\\Domain\\AI\\Services\\Texts\\Embeddings\\',
            'DDD\\Domain\\AI\\Services\\WebSearch\\',
            'DDD\\Domain\\AI\\Services\\ClientTools\\',
            'DDD\\Domain\\AI\\Services\\SystemHealth\\',
        ];
    }

    /**
     * The framework defaults plus the value objects of the presentation layer (request and response DTOs) and the
     * module's own non-service plumbing (`Modules`), none of which the container may autowire.
     */
    public static function getExcludePatterns(): array
    {
        return [...parent::getExcludePatterns(), 'Dtos', 'Modules'];
    }
}
