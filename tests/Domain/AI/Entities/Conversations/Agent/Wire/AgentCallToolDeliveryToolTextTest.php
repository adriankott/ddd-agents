<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentCallToolDelivery;
use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `call_tool` delivery's fixed schema and the text that lists a loaded skill's tools: the compact signature
 * (`callTool`) with enums, unions, arrays, nested objects and nodes the renderer does not know, and the JSON-schema form
 * (`callToolJsonSchema`).
 */
class AgentCallToolDeliveryToolTextTest extends UnitTestCase
{
    public function testTheCallToolSchemaIsAFixedChatFormFunctionWithNameAndArguments(): void
    {
        $callToolSchema = AgentCallToolDelivery::buildCallToolSchema();

        self::assertSame($callToolSchema, AgentCallToolDelivery::buildCallToolSchema(), 'the same bytes on every call');
        self::assertSame('function', $callToolSchema['type']);
        self::assertSame('call_tool', $callToolSchema['function']['name']);
        self::assertSame(['name', 'arguments'], $callToolSchema['function']['parameters']['required']);
        self::assertSame('string', $callToolSchema['function']['parameters']['properties']['name']['type']);
        self::assertSame('object', $callToolSchema['function']['parameters']['properties']['arguments']['type']);
        self::assertTrue($callToolSchema['function']['parameters']['properties']['arguments']['additionalProperties']);
        self::assertStringContainsString(AgentCallToolDelivery::HEADER_TOOLS_LISTED, $callToolSchema['function']['description']);
        self::assertStringContainsString('`load_skills`', $callToolSchema['function']['description']);
    }

    public function testAScalarEnumUnionAndArrayAreRenderedCompactly(): void
    {
        self::assertSame('string', AgentCallToolDelivery::renderType(['type' => 'string'], 0));
        self::assertSame('"open" | "closed" | 3 | null', AgentCallToolDelivery::renderType(['type' => 'string', 'enum' => ['open', 'closed', 3, null]], 0));
        self::assertSame('string | null', AgentCallToolDelivery::renderType(['type' => ['string', 'null']], 0));
        self::assertSame('integer[]', AgentCallToolDelivery::renderType(['type' => 'array', 'items' => ['type' => 'integer']], 0));
        self::assertSame('("a" | "b")[]', AgentCallToolDelivery::renderType(['type' => 'array', 'items' => ['enum' => ['a', 'b']]], 0), 'an item union is parenthesised');
        self::assertSame('any[]', AgentCallToolDelivery::renderType(['type' => 'array'], 0), 'an array without items');
        self::assertSame('any', AgentCallToolDelivery::renderType([], 0));
    }

    public function testAnUnknownNodeIsRenderedAsItsJson(): void
    {
        self::assertSame('{"$ref":"#/defs/Location"}', AgentCallToolDelivery::renderType(['$ref' => '#/defs/Location'], 0));
        self::assertSame(
            '{"oneOf":[{"type":"string"},{"type":"integer"}]}',
            AgentCallToolDelivery::renderType(['oneOf' => [['type' => 'string'], ['type' => 'integer']]], 0)
        );
    }

    public function testAnObjectWithoutPropertiesIsAnOpenObjectOrAnEmptyOne(): void
    {
        self::assertSame('{}', AgentCallToolDelivery::renderType(['type' => 'object'], 0));
        self::assertSame('{}', AgentCallToolDelivery::renderType(['type' => 'object', 'additionalProperties' => false], 0));
        self::assertSame('object', AgentCallToolDelivery::renderType(['type' => 'object', 'additionalProperties' => true], 0));
        self::assertSame('object', AgentCallToolDelivery::renderType(['type' => 'object', 'additionalProperties' => ['type' => 'string']], 0));
    }

    public function testANestedObjectMarksOptionalPropertiesAndCarriesDescriptionsAsComments(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['locationId', 'filter'],
            'properties' => [
                'locationId' => ['type' => 'integer', 'description' => 'The location'],
                'filter' => [
                    'type' => 'object',
                    'required' => ['status'],
                    'properties' => [
                        'status' => ['type' => 'string', 'enum' => ['open', 'closed']],
                        'since' => ['type' => 'string', 'description' => "Start date\nformat Y-m-d"],
                    ],
                ],
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];

        self::assertSame(
            "{\n"
                . "  // The location\n"
                . "  locationId: integer,\n"
                . "  filter: {\n"
                . "    status: \"open\" | \"closed\",\n"
                . "    // Start date\n"
                . "    // format Y-m-d\n"
                . "    since?: string,\n"
                . "  },\n"
                . "  tags?: string[],\n"
                . '}',
            AgentCallToolDelivery::renderType($schema, 0)
        );
    }

    public function testAnObjectIsRecognisedByItsPropertiesWithoutAType(): void
    {
        self::assertSame("{\n  query?: string,\n}", AgentCallToolDelivery::renderType(['properties' => ['query' => ['type' => 'string']]], 0));
    }

    public function testAToolIsRenderedAsItsSignatureWithItsExamplesFromTheChatOrTheFlatForm(): void
    {
        $function = [
            'name' => 'getReviews',
            'description' => '  The reviews of a location.  ',
            'parameters' => [
                'type' => 'object',
                'required' => ['locationId'],
                'properties' => ['locationId' => ['type' => 'integer'], 'limit' => ['type' => 'integer']],
                'examples' => [['locationId' => 7, 'limit' => 'ä/b'], []],
            ],
        ];
        $expectedText = "## getReviews\n"
            . "The reviews of a location.\n"
            . "Arguments: {\n  locationId: integer,\n  limit?: integer,\n}\n"
            . 'Example: call_tool({"name":"getReviews","arguments":{"locationId":7,"limit":"ä/b"}})' . "\n"
            . 'Example: call_tool({"name":"getReviews","arguments":{}})';

        self::assertSame($expectedText, AgentCallToolDelivery::renderToolAsSignature(['type' => 'function', 'function' => $function]));
        self::assertSame($expectedText, AgentCallToolDelivery::renderToolAsSignature($function), 'the flat Responses form');
    }

    public function testAToolWithoutDescriptionOrParametersAndAToolWithoutName(): void
    {
        self::assertSame("## ping\nArguments: any", AgentCallToolDelivery::renderToolAsSignature(['name' => 'ping']));
        self::assertSame('', AgentCallToolDelivery::renderToolAsSignature(['description' => 'no name']));
        self::assertSame('', AgentCallToolDelivery::renderToolAsJsonSchema(['type' => 'function', 'function' => ['name' => '']]));
    }

    public function testAToolIsRenderedAsItsJsonSchema(): void
    {
        $toolSchema = [
            'type' => 'function',
            'function' => [
                'name' => 'getReviews',
                'description' => 'The reviews.',
                'parameters' => ['type' => 'object', 'properties' => ['url' => ['type' => 'string', 'description' => 'https://x/ü']]],
            ],
        ];

        self::assertSame(
            "## getReviews\nThe reviews.\n" . 'Arguments (JSON schema): {"type":"object","properties":{"url":{"type":"string","description":"https://x/ü"}}}',
            AgentCallToolDelivery::renderToolAsJsonSchema($toolSchema)
        );
        self::assertSame("## ping\nArguments (JSON schema): {}", AgentCallToolDelivery::renderToolAsJsonSchema(['name' => 'ping', 'parameters' => []]));
    }

    public function testTheToolsTextLeadsWithTheHeaderAndTheCallFormAndSkipsNamelessTools(): void
    {
        $toolSchemas = [
            ['type' => 'function', 'function' => ['name' => 'getReviews', 'parameters' => ['type' => 'object']]],
            ['type' => 'function', 'function' => ['description' => 'nameless']],
            ['name' => 'ping'],
        ];

        $signatureText = AgentCallToolDelivery::buildToolsText($toolSchemas, ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL);
        self::assertStringStartsWith(AgentCallToolDelivery::HEADER_TOOLS_LISTED . "\nCall form: `call_tool({\"name\": \"<tool name>\", \"arguments\": {…}})`.", $signatureText);
        self::assertStringEndsWith("\n\n## getReviews\nArguments: {}\n\n## ping\nArguments: any", $signatureText);
        self::assertStringNotContainsString('nameless', $signatureText);

        $jsonSchemaText = AgentCallToolDelivery::buildToolsText($toolSchemas, ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA);
        self::assertStringStartsWith(AgentCallToolDelivery::HEADER_TOOLS_LISTED . "\n", $jsonSchemaText);
        self::assertStringEndsWith("\n\n## getReviews\nArguments (JSON schema): {\"type\":\"object\"}\n\n## ping\nArguments (JSON schema): {}", $jsonSchemaText);
    }

    public function testNoToolGivesNoText(): void
    {
        self::assertSame('', AgentCallToolDelivery::buildToolsText([], ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL));
        self::assertSame('', AgentCallToolDelivery::buildToolsText([['description' => 'nameless']], ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL));
    }

    public function testTheRefusalOfACallWithoutToolNameStatesTheCallForm(): void
    {
        $refusal = AgentCallToolDelivery::buildRefusalOfCallWithoutToolName();

        self::assertStringStartsWith('Not executed: `call_tool` needs the name of the tool to run.', $refusal);
        self::assertStringContainsString('call_tool({"name": "<tool name>", "arguments": {…}})', $refusal);
        self::assertStringContainsString(AgentCallToolDelivery::HEADER_TOOLS_LISTED, $refusal);
        self::assertStringEndsWith('Nothing was done.', $refusal);
    }
}
