<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\DecisionFixtures;

use DDD\Symfony\Commands\Agents\DecisionFixtures\AgentDecisionFixture;
use DDD\Symfony\Commands\Agents\Eval\AgentEvalCompactionTreeCaseFile;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * A decision fixture and a compaction-tree case file hand out an application's own key only through typed readers:
 * a whole number or a text, never a decoded structure.
 */
class AgentDecisionFixtureTypedReadersTest extends UnitTestCase
{
    public function testAnApplicationsKeyIsReadAsANumberOrATextAndAStructureIsNeverHandedOut(): void
    {
        $documentJson = '{"ownScopeId": 41, "ownScopeIdAsText": "42", "label": "north", "ratio": 0.5, "nested": {"id": 7}, "list": [1, 2]}';

        foreach ([AgentDecisionFixture::fromJson($documentJson), AgentEvalCompactionTreeCaseFile::fromJson($documentJson)] as $document) {
            self::assertSame(41, $document->getInteger('ownScopeId'));
            self::assertSame(42, $document->getInteger('ownScopeIdAsText'));
            self::assertNull($document->getInteger('label'));
            self::assertNull($document->getInteger('missing'));
            self::assertSame('north', $document->getScalarText('label'));
            self::assertSame('41', $document->getScalarText('ownScopeId'));
            self::assertSame('0.5', $document->getScalarText('ratio'));
            self::assertNull($document->getInteger('nested'), 'a structure is no number');
            self::assertNull($document->getScalarText('list'), 'a structure is no text');
        }
    }
}
