<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Contract;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use PHPUnit\Framework\TestCase;

/**
 * PHP does not inherit attributes, so a host's subclass of the conversation repeats the class-level attributes of the
 * module's class verbatim. The entry skill lists them; this test keeps the list equal to the class.
 */
class ConversationClassAttributesDocumentedForHostSubclassesTest extends TestCase
{
    protected const string OVERRIDE_POINTS_REFERENCE = '/.claude/skills/ddd-module-agents-specialist/references/override-points.md';

    public function testTheEntrySkillListsEveryClassAttributeOfTheConversationVerbatim(): void
    {
        $classSource = (string)file_get_contents((string)(new \ReflectionClass(AIConversation::class))->getFileName());
        $classDeclarationPosition = (int)strpos($classSource, "\nclass AIConversation ");
        preg_match_all('/^#\[[^\n]+\]$/m', substr($classSource, 0, $classDeclarationPosition), $attributeMatches);
        $classAttributeLines = $attributeMatches[0];
        $reference = (string)file_get_contents(dirname(__DIR__, 2) . self::OVERRIDE_POINTS_REFERENCE);
        preg_match_all('/^  #\[[^\n]+\]$/m', $reference, $documentedMatches);
        $documentedAttributeLines = array_map('trim', $documentedMatches[0]);

        self::assertNotSame([], $classAttributeLines);
        self::assertSame($classAttributeLines, $documentedAttributeLines, 'the attributes a host subclass of AIConversation repeats');
    }
}
