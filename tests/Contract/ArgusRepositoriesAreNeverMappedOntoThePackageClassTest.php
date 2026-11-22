<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Tests\Agents\Support\AgentPackageSourceRoots;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * No class of the package maps an Argus repository with a bare `toEntity()`.
 *
 * Without an instance, `ArgusTrait::toEntity()` builds the repository's parent class with `new`: always the package's
 * class, so an application's same-path subclass (a `WebPageMarkdown` with its own lines, a `WebSearchHitList`, a
 * summary) is never reached for what was actually loaded. The package passes `X::newInstance()` as `entityInstance`.
 */
class ArgusRepositoriesAreNeverMappedOntoThePackageClassTest extends UnitTestCase
{
    /** @var string A `toEntity()` call without arguments */
    public const string BARE_TO_ENTITY_PATTERN = '/->toEntity\(\s*\)/';

    public function testNoPackageClassMapsAnArgusRepositoryWithoutAnInstance(): void
    {
        $filesWithBareToEntity = [];
        foreach (AgentPackageSourceRoots::findPhpFiles() as $relativePath => $absolutePath) {
            if (preg_match(static::BARE_TO_ENTITY_PATTERN, static::buildCodeWithoutComments((string)file_get_contents($absolutePath))) === 1) {
                $filesWithBareToEntity[] = $relativePath;
            }
        }

        self::assertSame([], $filesWithBareToEntity, 'pass X::newInstance() as entityInstance: an application may subclass the class at the same path');
    }

    public function testThePatternFindsABareCallOnly(): void
    {
        self::assertSame(1, preg_match(static::BARE_TO_ENTITY_PATTERN, '$argusWebPageMarkdown->toEntity()'));
        self::assertSame(0, preg_match(static::BARE_TO_ENTITY_PATTERN, '$argusWebPageMarkdown->toEntity(entityInstance: $webPageMarkdownInstance)'));
    }

    /** The source without its comments: a comment that names `toEntity()` is no call */
    protected static function buildCodeWithoutComments(string $source): string
    {
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }
        return $code;
    }
}
