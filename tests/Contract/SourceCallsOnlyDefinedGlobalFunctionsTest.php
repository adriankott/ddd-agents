<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\Services\Conversations\AgentOwnerFacingTextTranslationService;
use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;
use PhpToken;

/**
 * Every function the package source calls by name exists in the package's own run: a PHP function, or one the package
 * or its dependencies define (the framework's `__()` among them). This run loads no application, so a function only an
 * application defines is missing here — and a host without that function gets "Call to undefined function" at
 * runtime, typically on a failure path no other test reaches.
 *
 * A call guarded by `function_exists('<name>')` in the same file is allowed: the file handles the missing function.
 */
class SourceCallsOnlyDefinedGlobalFunctionsTest extends UnitTestCase
{
    /** @var int[] Tokens before a name that make the name no function call (method, static call, declaration, instantiation) */
    public const array TOKENS_BEFORE_A_NAME_THAT_IS_NO_FUNCTION_CALL = [
        T_OBJECT_OPERATOR,
        T_NULLSAFE_OBJECT_OPERATOR,
        T_DOUBLE_COLON,
        T_FUNCTION,
        T_FN,
        T_NEW,
        T_CONST,
        T_ENUM,
        T_CLASS,
        T_INTERFACE,
        T_TRAIT,
        T_USE,
    ];

    /** @var int[] Comments and white space, skipped when the neighbours of a token are read */
    public const array IGNORED_TOKENS = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    public function testTheSourceCallsNoGlobalFunctionThatNeitherThePackageNorItsDependenciesDefine(): void
    {
        $undefinedFunctionCalls = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            foreach ($this->findCalledFunctionNames((string)file_get_contents($absolutePath)) as $calledFunctionName => $resolvableNames) {
                if (!$this->isAnyFunctionDefined($resolvableNames)) {
                    $undefinedFunctionCalls[] = $relativePath . ': ' . $calledFunctionName . '()';
                }
            }
        }
        self::assertSame([], $undefinedFunctionCalls, "the source calls functions that neither the package nor its dependencies define:\n" . implode("\n", $undefinedFunctionCalls));
    }

    /**
     * The application-only translation function `___()`, by name: whatever an earlier test or the run loaded, the
     * package calls it only through {@see AgentOwnerFacingTextTranslationService}, which checks that it exists.
     */
    public function testTheSourceNeverCallsTheApplicationTranslationFunctionDirectly(): void
    {
        $directCalls = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            foreach (PhpToken::tokenize((string)file_get_contents($absolutePath)) as $token) {
                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && ltrim($token->text, '\\') === AgentOwnerFacingTextTranslationService::HOST_TRANSLATION_FUNCTION_NAME) {
                    $directCalls[] = $relativePath . ':' . $token->line;
                }
            }
        }
        self::assertSame([], $directCalls, 'call AgentOwnerFacingTextTranslationService instead of ___(): ' . implode(', ', $directCalls));
    }

    public function testTheScannerFindsAnUndefinedCallAndSkipsMethodsDeclarationsAndGuardedCalls(): void
    {
        $source = <<<'PHP'
            <?php
            namespace Some\Space;
            use function Other\imported_function;
            #[SomeAttribute(value: 1)]
            class Example {
                public function method(): void {
                    $this->method();
                    self::method();
                    new Example();
                    strlen('x');
                    \strtoupper('x');
                    not_defined_anywhere('x');
                    imported_function();
                    if (function_exists('guarded_function')) { guarded_function(); }
                }
            }
            PHP;
        self::assertSame(
            [
                'strlen' => ['Some\\Space\\strlen', 'strlen'],
                'strtoupper' => ['strtoupper'],
                'not_defined_anywhere' => ['Some\\Space\\not_defined_anywhere', 'not_defined_anywhere'],
                'imported_function' => ['Other\\imported_function'],
                'function_exists' => ['Some\\Space\\function_exists', 'function_exists'],
            ],
            $this->findCalledFunctionNames($source)
        );
    }

    /**
     * The functions a source calls by name, each with the names PHP resolves it to (the namespaced name first, then
     * the global fallback of an unqualified call). Calls guarded by `function_exists()` in the source are left out.
     *
     * @param string $source
     * @return array<string, string[]> called name => resolvable fully qualified names
     */
    protected function findCalledFunctionNames(string $source): array
    {
        $significantTokens = [];
        foreach (PhpToken::tokenize($source) as $token) {
            if (!$token->is(self::IGNORED_TOKENS)) {
                $significantTokens[] = $token;
            }
        }
        $namespace = '';
        $importedFunctionNamesByAlias = $this->findImportedFunctionNames($significantTokens);
        $guardedFunctionNames = $this->findFunctionNamesGuardedByFunctionExists($significantTokens);
        $calledFunctionNames = [];
        $attributeBracketDepth = 0;
        foreach ($significantTokens as $tokenIndex => $token) {
            if ($token->is(T_NAMESPACE) && isset($significantTokens[$tokenIndex + 1]) && $significantTokens[$tokenIndex + 1]->is([T_NAME_QUALIFIED, T_STRING])) {
                $namespace = $significantTokens[$tokenIndex + 1]->text;
                continue;
            }
            // an attribute's arguments are constant expressions: a name followed by `(` there is a class
            if ($token->is(T_ATTRIBUTE)) {
                $attributeBracketDepth = 1;
                continue;
            }
            if ($attributeBracketDepth > 0) {
                if ($token->text === '[') {
                    $attributeBracketDepth++;
                } elseif ($token->text === ']') {
                    $attributeBracketDepth--;
                }
                continue;
            }
            if (!$token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])) {
                continue;
            }
            if (($significantTokens[$tokenIndex + 1] ?? null)?->text !== '(') {
                continue;
            }
            $previousToken = $significantTokens[$tokenIndex - 1] ?? null;
            if ($previousToken !== null && ($previousToken->is(self::TOKENS_BEFORE_A_NAME_THAT_IS_NO_FUNCTION_CALL)
                || ($previousToken->text === '&' && ($significantTokens[$tokenIndex - 2] ?? null)?->is([T_FUNCTION, T_FN])))
            ) {
                continue;
            }
            $calledName = ltrim($token->text, '\\');
            if (in_array(strtolower($calledName), $guardedFunctionNames, true)) {
                continue;
            }
            $calledFunctionNames[$calledName] = $this->resolveFunctionNames($token, $calledName, $namespace, $importedFunctionNamesByAlias);
        }
        return $calledFunctionNames;
    }

    /**
     * @param PhpToken $nameToken
     * @param string $calledName
     * @param string $namespace
     * @param array<string, string> $importedFunctionNamesByAlias
     * @return string[]
     */
    protected function resolveFunctionNames(PhpToken $nameToken, string $calledName, string $namespace, array $importedFunctionNamesByAlias): array
    {
        if ($nameToken->is(T_NAME_FULLY_QUALIFIED)) {
            return [$calledName];
        }
        if (isset($importedFunctionNamesByAlias[strtolower($calledName)])) {
            return [$importedFunctionNamesByAlias[strtolower($calledName)]];
        }
        $namespacedName = $namespace !== '' ? $namespace . '\\' . $calledName : $calledName;
        if ($nameToken->is(T_NAME_QUALIFIED) || $namespacedName === $calledName) {
            return [$namespacedName];
        }
        return [$namespacedName, $calledName];
    }

    /**
     * The `use function A\b [as c];` imports of a source.
     *
     * @param PhpToken[] $significantTokens
     * @return array<string, string> lower-case alias => fully qualified function name
     */
    protected function findImportedFunctionNames(array $significantTokens): array
    {
        $importedFunctionNamesByAlias = [];
        foreach ($significantTokens as $tokenIndex => $token) {
            if (!$token->is(T_USE) || ($significantTokens[$tokenIndex + 1] ?? null)?->is(T_FUNCTION) !== true) {
                continue;
            }
            $importedNameToken = $significantTokens[$tokenIndex + 2] ?? null;
            if ($importedNameToken === null || !$importedNameToken->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])) {
                continue;
            }
            $importedName = ltrim($importedNameToken->text, '\\');
            $nameParts = explode('\\', $importedName);
            $alias = end($nameParts);
            if (($significantTokens[$tokenIndex + 3] ?? null)?->is(T_AS) === true) {
                $alias = (string)($significantTokens[$tokenIndex + 4] ?? null)?->text;
            }
            $importedFunctionNamesByAlias[strtolower($alias)] = $importedName;
        }
        return $importedFunctionNamesByAlias;
    }

    /**
     * The names a source checks with `function_exists('<name>')`.
     *
     * @param PhpToken[] $significantTokens
     * @return string[] lower-case names
     */
    protected function findFunctionNamesGuardedByFunctionExists(array $significantTokens): array
    {
        $guardedFunctionNames = [];
        foreach ($significantTokens as $tokenIndex => $token) {
            if (!$token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || strtolower(ltrim($token->text, '\\')) !== 'function_exists') {
                continue;
            }
            $argumentToken = $significantTokens[$tokenIndex + 2] ?? null;
            if ($argumentToken !== null && $argumentToken->is(T_CONSTANT_ENCAPSED_STRING)) {
                $guardedFunctionNames[] = strtolower(ltrim(trim($argumentToken->text, '\'"'), '\\'));
            }
        }
        return $guardedFunctionNames;
    }

    /**
     * @param string[] $resolvableNames
     * @return bool
     */
    protected function isAnyFunctionDefined(array $resolvableNames): bool
    {
        foreach ($resolvableNames as $resolvableName) {
            if (function_exists($resolvableName)) {
                return true;
            }
        }
        return false;
    }
}
