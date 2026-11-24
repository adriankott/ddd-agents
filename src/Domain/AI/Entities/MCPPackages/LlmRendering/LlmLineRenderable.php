<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\MCPPackages\LlmRendering;

/**
 * A domain object that renders ITSELF as the one line a list shows for it to the admin agent:
 * `#<id> <name> · <field> · <field>`, with the one or two fields that tell similar records apart. Two tools that list
 * the same kind of record therefore read the same, and no tool formats a record's fields.
 *
 * A pure fold over the object's own loaded data: no repository contact, no lazy load (read related objects with
 * isset()). The full rendering of an object is {@see \DDD\Domain\Base\Entities\LlmMarkdownRenderable}.
 */
interface LlmLineRenderable
{
    /**
     * @return string One line without a line break, starting with `#<id>`
     */
    public function toLlmLine(): string;
}
