<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Goals\Blueprints;

use DDD\Domain\AI\Entities\Conversations\Goals\AgentGoal;
use DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprint;
use DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprintNode;
use DDD\Domain\AI\Entities\Conversations\Goals\Blueprints\AgentGoalBlueprintNodes;
use DDD\Presentation\Mcp\Client\Agents\Dtos\AgentGoalBlueprintMcpResponseDto;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The standard goal tree as values: the nodes are keyed by their key (a second node with the same key is not added),
 * the tree renders indented with one marker per assessment, a deferred node says it cannot be measured, a node whose
 * parent was pruned becomes a root, and a loop of parent keys renders every node once.
 */
class AgentGoalBlueprintTest extends UnitTestCase
{
    public function testTheNodesAreKeyedByTheirKey(): void
    {
        $agentGoalBlueprintNodes = new AgentGoalBlueprintNodes();
        $firstNode = AgentGoalBlueprintNode::define('reviews.answered', 'Every review gets an answer');
        $agentGoalBlueprintNodes->add($firstNode);
        $sameKeyNode = AgentGoalBlueprintNode::define('reviews.answered', 'Another statement under the same key');
        $agentGoalBlueprintNodes->add($sameKeyNode);

        self::assertSame(1, $agentGoalBlueprintNodes->count());
        self::assertSame('Every review gets an answer', $agentGoalBlueprintNodes->findNodeByKey('reviews.answered')?->statement);
        self::assertNull($agentGoalBlueprintNodes->findNodeByKey('reviews'));
        self::assertSame(AgentGoalBlueprintNode::class . '_reviews.answered', $firstNode->uniqueKey());
    }

    public function testTheTreeRendersIndentedWithOneMarkerPerAssessment(): void
    {
        $agentGoalBlueprint = $this->buildBakeryBlueprint();

        self::assertSame(
            implode("\n", [
                AgentGoalBlueprint::HEADER,
                '🎯 The bakery thrives',
                '  ▸ `presence` People find the bakery',
                '    ✅ `presence.hours` The opening hours are complete — all seven days carry hours [7 of 7]',
                '    ⬜ `presence.photos` Fresh photos every month — a photo in the last 30 days [last photo 52 days ago]',
                '    ❔ `presence.menu` The menu is online — a menu link on the profile',
                '  ▸ `reviews` Guests speak well of the bakery',
                '    · `reviews.answered` Every review gets an answer — answered within two days',
                '    · `reviews.trend` The rating rises _(not yet measurable)_',
            ]),
            $agentGoalBlueprint->toLlmMarkdown()
        );
        self::assertSame(AgentGoalBlueprintNode::CRITERION_TYPE_CADENCE, $agentGoalBlueprint->findNodeByKey('reviews.answered')?->criterionType);
    }

    public function testANodeWhoseParentWasPrunedBecomesARootAndALoopRendersEveryNodeOnce(): void
    {
        $agentGoalBlueprint = new AgentGoalBlueprint();
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('catering', 'Catering fills the weekends', 'pruned.area'));
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('loop.a', 'A', 'loop.b'));
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('loop.b', 'B', 'loop.a'));

        self::assertSame(AgentGoalBlueprint::HEADER . "\n🎯 Catering fills the weekends", $agentGoalBlueprint->toLlmMarkdown());
        self::assertSame(AgentGoalBlueprint::HEADER, (new AgentGoalBlueprint())->toLlmMarkdown());
    }

    public function testTheAnswerAddsTheLegendAndSaysWhenThereIsNoTree(): void
    {
        $toolResultText = AgentGoalBlueprintMcpResponseDto::forBlueprint($this->buildBakeryBlueprint())->toLlmMarkdown();

        self::assertStringStartsWith(AgentGoalBlueprint::HEADER, $toolResultText);
        self::assertStringContainsString("\n\n" . AgentGoalBlueprintMcpResponseDto::LEGEND . "\n\n" . AgentGoalBlueprintMcpResponseDto::NOTE_HOW_TO_USE, $toolResultText);
        self::assertSame(AgentGoalBlueprintMcpResponseDto::NO_BLUEPRINT_LINE, AgentGoalBlueprintMcpResponseDto::forBlueprint(null)->toLlmMarkdown());
    }

    public static function buildBakeryBlueprint(): AgentGoalBlueprint
    {
        $agentGoalBlueprint = new AgentGoalBlueprint();
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('bakery', 'The bakery thrives'));
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('presence', 'People find the bakery', 'bakery'));
        $hoursNode = AgentGoalBlueprintNode::define('presence.hours', 'The opening hours are complete', 'presence', 'hoursComplete', 'all seven days carry hours', AgentGoalBlueprintNode::CRITERION_TYPE_STATE, 3, horizonLevel: AgentGoal::HORIZON_SHORT_TERM);
        $hoursNode->assess(AgentGoalBlueprintNode::ASSESSMENT_MET, '7 of 7');
        $agentGoalBlueprint->addNode($hoursNode);
        $photosNode = AgentGoalBlueprintNode::define('presence.photos', 'Fresh photos every month', 'presence', 'photoCadence', 'a photo in the last 30 days', AgentGoalBlueprintNode::CRITERION_TYPE_CADENCE, 2);
        $photosNode->assess(AgentGoalBlueprintNode::ASSESSMENT_OPEN, 'last photo 52 days ago');
        $agentGoalBlueprint->addNode($photosNode);
        $menuNode = AgentGoalBlueprintNode::define('presence.menu', 'The menu is online', 'presence', 'menuLinked', 'a menu link on the profile', AgentGoalBlueprintNode::CRITERION_TYPE_STATE);
        $menuNode->assess(AgentGoalBlueprintNode::ASSESSMENT_UNKNOWN, ' ');
        $agentGoalBlueprint->addNode($menuNode);
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('reviews', 'Guests speak well of the bakery', 'bakery'));
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('reviews.answered', 'Every review gets an answer', 'reviews', 'reviewsAnswered', 'answered within two days', AgentGoalBlueprintNode::CRITERION_TYPE_CADENCE));
        $agentGoalBlueprint->addNode(AgentGoalBlueprintNode::define('reviews.trend', 'The rating rises', 'reviews', 'ratingTrend', 'the rating rises over 90 days', AgentGoalBlueprintNode::CRITERION_TYPE_TREND, status: AgentGoalBlueprintNode::STATUS_DEFERRED));
        return $agentGoalBlueprint;
    }
}
