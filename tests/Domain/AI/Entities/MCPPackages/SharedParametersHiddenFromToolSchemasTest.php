<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\MCPPackages;

use DDD\Domain\AI\Entities\MCPPackages\MCPObjectProperty;
use DDD\Domain\AI\Entities\MCPPackages\MCPSchema;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Tests\Agents\Domain\AI\Entities\MCPPackages\Probes\ProbeRequestWithDateParameters;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The shared request parameters the executor controls are no part of a tool schema: `outputFormat` (it bypassed the
 * markdown rendering) and `noCache` (nothing reads it on a tool call; it cost tokens on every tool). The paging
 * parameters and the tool's own parameters stay.
 */
class SharedParametersHiddenFromToolSchemasTest extends UnitTestCase
{
    public function testOutputFormatAndNoCacheAreHiddenTheOtherParametersStay(): void
    {
        self::assertSame(['outputFormat', 'noCache'], MCPObjectProperty::MCP_HIDDEN_SHARED_PARAMETERS);
        self::assertNull($this->documentProperty('noCache'));
        self::assertNull($this->documentProperty('outputFormat'));
        self::assertInstanceOf(MCPObjectProperty::class, $this->documentProperty('firstDay'), 'the tool\'s own parameter');
    }

    protected function documentProperty(string $propertyName): ?MCPObjectProperty
    {
        $reflectionClass = ReflectionClass::instance(ProbeRequestWithDateParameters::class);
        /** @var MCPSchema $mcpSchema */
        $mcpSchema = $this->instantiateWithoutConstructor(MCPSchema::class);
        return MCPObjectProperty::createFromReflectionProperty($mcpSchema, $reflectionClass, $reflectionClass->getProperty($propertyName));
    }
}
