<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Agent\Wire;

use DDD\Domain\AI\Entities\Conversations\Messages\ContextRetentionSettings;
use DDD\Domain\AI\Entities\Conversations\Messages\ToolCall;

/**
 * The `call_tool` delivery of an append-only conversation ({@see ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL}):
 * the top-level `tools` are the meta tools plus ONE fixed tool, `call_tool(name, arguments)`, for the whole
 * conversation. A loaded skill's tools are never declared as tools of their own — the developer message of the load
 * lists each of them as text (a compact signature, or its JSON schema), and the model calls them through `call_tool`.
 * The platform unwraps such a call into the call of the named tool right after the model's answer
 * ({@see unwrapCall()}), so the dispatch, the guards, the stored row and the traces read the real tool name; the
 * history sends the call back in the form the model wrote it ({@see ToolCall::toOpenAiToolCallArray()}).
 * Pure.
 */
class AgentCallToolDelivery
{
    public const string NAME = 'call_tool';

    /** The argument of `call_tool` that names the tool. */
    public const string ARGUMENT_NAME = 'name';

    /** The argument of `call_tool` that carries the tool's own arguments. */
    public const string ARGUMENT_ARGUMENTS = 'arguments';

    /** Leads the tools a call appended as text. */
    public const string HEADER_TOOLS_LISTED = '# Tools added now — call each one through `call_tool`';

    /**
     * The fixed Chat-form schema of `call_tool` — the same bytes on every call of the conversation.
     *
     * @return array<string, mixed>
     */
    public static function buildCallToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => self::NAME,
                'description' => 'Runs ONE tool of a loaded skill. The tools of a skill are not in your tool list: they are listed '
                    . 'as text in the conversation where the skill was loaded (under "' . self::HEADER_TOOLS_LISTED . '"), each with '
                    . 'its name, what it does and its arguments. Pass the exact tool name and the tool\'s arguments as a JSON '
                    . 'object. Several `call_tool` calls in one step run in parallel, like any other tools. A tool whose skill is '
                    . 'not loaded cannot run: load the skill with `load_skills` first. The listed tools cover only the skills '
                    . 'loaded so far: when the request needs something none of them does, load the skill that covers it with '
                    . '`load_skills` before you call a tool — never answer from a tool that only comes close. Where a '
                    . 'message says a tool is "in your tool list" or tells you to call it directly, it means such a listed '
                    . 'tool: call it through `call_tool`.',
                'parameters' => [
                    'type' => 'object',
                    'required' => [self::ARGUMENT_NAME, self::ARGUMENT_ARGUMENTS],
                    'properties' => [
                        self::ARGUMENT_NAME => [
                            'type' => 'string',
                            'description' => 'The exact name of the tool, as listed.',
                        ],
                        self::ARGUMENT_ARGUMENTS => [
                            'type' => 'object',
                            'description' => 'The tool\'s own arguments by its listed signature; {} when the call needs none.',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The text that lists `$toolSchemas` (Chat-form or flat schemas) for the model: the header, one line on how to call
     * them, then each tool with its description and its arguments — a compact signature under
     * {@see ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL}, the JSON schema under
     * {@see ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA}. '' for no tool.
     *
     * @param array<int, array<string, mixed>> $toolSchemas
     */
    public static function buildToolsText(array $toolSchemas, string $toolDelivery): string
    {
        $toolTexts = [];
        foreach ($toolSchemas as $toolSchema) {
            $toolText = $toolDelivery === ContextRetentionSettings::TOOL_DELIVERY_CALL_TOOL_JSON_SCHEMA
                ? self::renderToolAsJsonSchema($toolSchema)
                : self::renderToolAsSignature($toolSchema);
            if ($toolText !== '') {
                $toolTexts[] = $toolText;
            }
        }
        if ($toolTexts === []) {
            return '';
        }
        return self::HEADER_TOOLS_LISTED . "\n"
            . 'Call form: `call_tool({"name": "<tool name>", "arguments": {…}})`. In a signature `?` marks an argument you may '
            . "leave out; pass only arguments the signature names. These are the tools of the skills loaded so far, not all "
            . "there are: a request they do not cover belongs to another skill of the skill list — load that skill with "
            . "`load_skills` first.\n\n"
            . implode("\n\n", $toolTexts);
    }

    /**
     * One tool as its name, description and a compact signature of its arguments.
     *
     * @param array<string, mixed> $toolSchema
     */
    public static function renderToolAsSignature(array $toolSchema): string
    {
        $function = self::readFunctionOf($toolSchema);
        $toolName = (string)($function['name'] ?? '');
        if ($toolName === '') {
            return '';
        }
        $parameters = is_array($function['parameters'] ?? null) ? $function['parameters'] : [];
        $toolText = '## ' . $toolName;
        $description = trim((string)($function['description'] ?? ''));
        if ($description !== '') {
            $toolText .= "\n" . $description;
        }
        $toolText .= "\nArguments: " . self::renderType($parameters, 0);
        $examples = is_array($parameters['examples'] ?? null) ? $parameters['examples'] : [];
        foreach ($examples as $example) {
            $toolText .= "\nExample: " . self::NAME . '(' . self::encodeJson([self::ARGUMENT_NAME => $toolName, self::ARGUMENT_ARGUMENTS => is_array($example) && $example !== [] ? $example : (object)[]]) . ')';
        }
        return $toolText;
    }

    /**
     * One tool as its name, description and the JSON schema of its arguments.
     *
     * @param array<string, mixed> $toolSchema
     */
    public static function renderToolAsJsonSchema(array $toolSchema): string
    {
        $function = self::readFunctionOf($toolSchema);
        $toolName = (string)($function['name'] ?? '');
        if ($toolName === '') {
            return '';
        }
        $toolText = '## ' . $toolName;
        $description = trim((string)($function['description'] ?? ''));
        if ($description !== '') {
            $toolText .= "\n" . $description;
        }
        $parameters = is_array($function['parameters'] ?? null) && $function['parameters'] !== [] ? $function['parameters'] : (object)[];
        return $toolText . "\nArguments (JSON schema): " . self::encodeJson($parameters);
    }

    /**
     * A JSON-schema node as a compact type: an object as `{ name?: type, … }` with each property's description as a
     * comment in front of it, an array as `type[]`, an enum as its quoted values, a scalar as its type name. A node this
     * renderer does not know (a reference, a combination) is rendered as its JSON, so nothing is lost.
     *
     * @param array<string, mixed> $schema
     */
    public static function renderType(array $schema, int $depth): string
    {
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return implode(' | ', array_map(static fn (mixed $enumValue): string => self::encodeJson($enumValue), $schema['enum']));
        }
        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            // a union of scalar types, `null` included
            return implode(' | ', array_map('strval', $type));
        }
        if ($type === 'array') {
            $items = is_array($schema['items'] ?? null) ? $schema['items'] : [];
            $itemType = $items === [] ? 'any' : self::renderType($items, $depth);
            return (str_contains($itemType, ' | ') ? '(' . $itemType . ')' : $itemType) . '[]';
        }
        if ($type === 'object' || isset($schema['properties'])) {
            $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
            if ($properties === []) {
                return ($schema['additionalProperties'] ?? false) !== false ? 'object' : '{}';
            }
            $requiredNames = is_array($schema['required'] ?? null) ? $schema['required'] : [];
            $indent = str_repeat('  ', $depth + 1);
            $lines = [];
            foreach ($properties as $propertyName => $propertySchema) {
                $propertySchema = is_array($propertySchema) ? $propertySchema : [];
                $propertyDescription = trim((string)($propertySchema['description'] ?? ''));
                if ($propertyDescription !== '') {
                    foreach (explode("\n", $propertyDescription) as $descriptionLine) {
                        $lines[] = rtrim($indent . '// ' . $descriptionLine);
                    }
                }
                $lines[] = $indent . $propertyName . (in_array($propertyName, $requiredNames, true) ? '' : '?') . ': ' . self::renderType($propertySchema, $depth + 1) . ',';
            }
            return "{\n" . implode("\n", $lines) . "\n" . str_repeat('  ', $depth) . '}';
        }
        if (is_string($type) && $type !== '') {
            return $type;
        }
        return $schema === [] ? 'any' : self::encodeJson($schema);
    }

    /**
     * Turn a call the model made in `call_tool` delivery into the call of the tool it means, in place:
     * `call_tool({"name": X, "arguments": {…}})` becomes the call of X with those arguments and is marked
     * ({@see ToolCall::$deliveredThroughCallTool}), so the history sends it back as the model wrote it. A call of a tool
     * outside the declared ones that names the tool directly is marked the same way (the history then carries it as a
     * `call_tool` call — a tool that was never declared is not sent back as a call of its own). A call wrapped twice
     * (`call_tool({"name": "call_tool", "arguments": {"name": X, "arguments": {…}}})`) is unwrapped to the call of X.
     * A `call_tool` call without a tool name stays as it is: the dispatch answers it with the call form.
     *
     * @param string[] $topLevelToolNames the tools this model call declared
     */
    public static function unwrapCall(ToolCall $toolCall, array $topLevelToolNames): void
    {
        $calledName = (string)($toolCall->name ?? '');
        if ($calledName !== self::NAME) {
            if ($calledName !== '' && $topLevelToolNames !== [] && !in_array($calledName, $topLevelToolNames, true)) {
                $toolCall->deliveredThroughCallTool = true;
            }
            return;
        }
        $wrapperArguments = $toolCall->arguments;
        $innerName = $wrapperArguments[self::ARGUMENT_NAME] ?? null;
        $twiceWrappedArguments = $wrapperArguments[self::ARGUMENT_ARGUMENTS] ?? null;
        if (is_string($innerName) && trim($innerName) === self::NAME && is_array($twiceWrappedArguments)
            && is_string($twiceWrappedArguments[self::ARGUMENT_NAME] ?? null) && trim($twiceWrappedArguments[self::ARGUMENT_NAME]) !== self::NAME
        ) {
            // the call wrapped twice: the inner object is the call the model means
            $wrapperArguments = $twiceWrappedArguments;
            $innerName = $wrapperArguments[self::ARGUMENT_NAME];
        }
        if (!is_string($innerName) || trim($innerName) === '' || trim($innerName) === self::NAME) {
            return;
        }
        $innerArguments = $wrapperArguments[self::ARGUMENT_ARGUMENTS] ?? null;
        if (is_string($innerArguments)) {
            // the arguments written as a JSON string instead of an object
            $decodedArguments = json_decode($innerArguments, true);
            $innerArguments = is_array($decodedArguments) ? $decodedArguments : [];
        } elseif (!is_array($innerArguments)) {
            // the tool's arguments written beside `name` instead of inside `arguments`
            $innerArguments = $wrapperArguments;
            unset($innerArguments[self::ARGUMENT_NAME], $innerArguments[self::ARGUMENT_ARGUMENTS]);
        }
        $toolCall->name = trim($innerName);
        $toolCall->arguments = $innerArguments;
        $toolCall->deliveredThroughCallTool = true;
    }

    /**
     * The JSON arguments of the `call_tool` call that stands for the call of `$toolName` with `$argumentsJson` (the
     * tool's own arguments, already encoded).
     */
    public static function buildWrappedArgumentsJson(string $toolName, string $argumentsJson): string
    {
        return '{' . self::encodeJson(self::ARGUMENT_NAME) . ':' . (string)json_encode($toolName) . ',' . self::encodeJson(self::ARGUMENT_ARGUMENTS) . ':' . $argumentsJson . '}';
    }

    /** The answer to a `call_tool` call that names no tool. */
    public static function buildRefusalOfCallWithoutToolName(): string
    {
        return 'Not executed: `' . self::NAME . '` needs the name of the tool to run. Call form: '
            . self::NAME . '({"' . self::ARGUMENT_NAME . '": "<tool name>", "' . self::ARGUMENT_ARGUMENTS . '": {…}}) — the tool names and their '
            . 'arguments are listed where their skill was loaded ("' . self::HEADER_TOOLS_LISTED . '"). Nothing was done.';
    }

    /**
     * The `function` part of a tool schema: the Chat form nests it, the flat Responses form is it.
     *
     * @param array<string, mixed> $toolSchema
     * @return array<string, mixed>
     */
    protected static function readFunctionOf(array $toolSchema): array
    {
        return is_array($toolSchema['function'] ?? null) ? $toolSchema['function'] : $toolSchema;
    }

    protected static function encodeJson(mixed $value): string
    {
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
