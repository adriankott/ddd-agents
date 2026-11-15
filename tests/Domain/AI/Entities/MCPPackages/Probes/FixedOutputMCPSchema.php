<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes;

use DDD\Domain\AI\Entities\MCPPackages\MCPSchema;

/** An input schema whose serialized form is given: the tests of what the loop's tool schema does with it. */
class FixedOutputMCPSchema extends MCPSchema
{
    /** @var array<string, mixed> The serialized schema */
    public array $serializedSchema = [];

    public function toObject(
        $cached = true,
        bool $returnUniqueKeyInsteadOfContent = false,
        array $path = [],
        bool $ignoreHideAttributes = false,
        bool $ignoreNullValues = true,
        bool $forPersistence = true,
        int $flags = 0
    ): mixed {
        return $this->serializedSchema;
    }
}
