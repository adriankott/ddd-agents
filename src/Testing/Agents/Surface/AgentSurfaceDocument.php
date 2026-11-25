<?php

declare(strict_types=1);

namespace DDD\Testing\Agents\Surface;

/**
 * The surface document of an application ({@see AgentSurfaceDocumentBuilder}): the five parts by name. The entries of
 * a part are JSON maps whose fields follow the public fields of the documented object (a skill, a tool, a purpose), so
 * they stay data; the parts themselves are typed here. {@see toArray()} gives the tree the comparer
 * ({@see AgentSurfaceDocumentComparer}) walks and a file holds, the parts in this order.
 *
 * A plain object on purpose: the comparison needs no kernel.
 */
class AgentSurfaceDocument
{
    public const string PART_SKILL_ORDER = 'skillOrder';

    public const string PART_SKILLS = 'skills';

    public const string PART_PACKAGES = 'packages';

    public const string PART_PURPOSES = 'purposes';

    public const string PART_BUNDLES = 'bundles';

    /**
     * @param string[] $skillOrder The catalog's skill names in the order the agent is offered them (one value, the
     *     application's own, never set against another document's)
     * @param array<string, mixed> $skills Skill name => its entry: public fields, tool names, the packages it includes
     * @param array<string, mixed> $packages Package key ({@see AgentSurfaceDocumentBuilder::buildPackageKeysByClass()})
     *     => its entry: package id, fields, tools with their parameters and wire schema
     * @param array<string, mixed> $purposes Dotted purpose name => its entry: class and public fields
     * @param array<string, mixed> $bundles HTTP MCP bundle class => its entry: fields and one value per tool
     */
    public function __construct(
        public array $skillOrder = [],
        public array $skills = [],
        public array $packages = [],
        public array $purposes = [],
        public array $bundles = [],
    ) {
    }

    /**
     * @return array<string, mixed> The document as the tree a file holds and the comparer walks
     */
    public function toArray(): array
    {
        return [
            static::PART_SKILL_ORDER => $this->skillOrder,
            static::PART_SKILLS => $this->skills,
            static::PART_PACKAGES => $this->packages,
            static::PART_PURPOSES => $this->purposes,
            static::PART_BUNDLES => $this->bundles,
        ];
    }
}
