<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentModelInput;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegment;
use DDD\Domain\AI\Entities\Conversations\Agent\Context\AgentContextSegmentType;
use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentAppendOnlyCall;
use DDD\Domain\AI\Entities\Conversations\Agent\Wire\AgentPrefixEpoch;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapabilities;
use DDD\Domain\AI\Entities\Conversations\Messages\LoadedCapability;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The system prompt's skill catalog marks the skills the same prompt build renders in its loaded-skills block: their
 * line says LOADED and that their tools are in the tool list, the other lines and the sub-skill suffixes stay as they
 * are. Without loaded skill names the block is byte for byte what it was before; the single line the admin measurement
 * counts is unchanged.
 */
class SkillCatalogMarksLoadedSkillsTest extends UnitTestCase
{
    public function testTheLineOfALoadedSkillIsMarkedAndTheOthersAreNot(): void
    {
        $block = LoadSkillsTool::renderCatalogBlock($this->catalog(), topLevelOnly: true, skillNamesOfTheLoadedSkillsBlock: ['Shop.Orders']);

        self::assertSame(
            LoadSkillsTool::CATALOG_HEADER
                . "- Shop.Catalog — Products, prices and stock of the shop\n"
                . '- Shop.Orders — LOADED, its tools are in your tool list — Orders of the shop: list, ship, refund',
            $block
        );
        self::assertSame(
            "\n\n# Available skills — load with load_skills when a request needs them\n"
                . 'This list names every skill, loaded or not. A skill marked LOADED, named under "Loaded skills", or announced as '
                . "loaded later in this conversation is loaded: its tools are in your tool list — call them, never load that skill again.\n",
            LoadSkillsTool::CATALOG_HEADER
        );
    }

    public function testTheSubSkillSuffixesOfAMarkedLineStayAsTheyAre(): void
    {
        $block = LoadSkillsTool::renderCatalogBlock($this->catalog(), skillNamesOfTheLoadedSkillsBlock: ['Shop.Orders']);

        self::assertStringContainsString(
            "\n- Shop.Orders — LOADED, its tools are in your tool list — Orders of the shop: list, ship, refund · unlocks after loading: Shop.Refunds",
            $block
        );
        self::assertStringContainsString("\n- Shop.Refunds — Refunds of an order", $block, 'a sub-skill that is not loaded is not marked');
        self::assertSame(
            LoadSkillsTool::renderCatalogLine($this->orders()),
            '- Shop.Orders — Orders of the shop: list, ship, refund · unlocks after loading: Shop.Refunds',
            'the single line the admin measurement counts is unchanged'
        );
    }

    public function testWithoutLoadedSkillNamesTheBlockIsTheSameAsBefore(): void
    {
        $expectedBlock = "\n\n# Available skills — load with load_skills when a request needs them\n"
            . 'This list names every skill, loaded or not. A skill that "Loaded skills" names is loaded: its tools are in your '
            . "tool list — call them, never load that skill again.\n"
            . "- Shop.Catalog — Products, prices and stock of the shop\n"
            . '- Shop.Orders — Orders of the shop: list, ship, refund';

        self::assertSame($expectedBlock, LoadSkillsTool::renderCatalogBlock($this->catalog(), topLevelOnly: true));
        self::assertSame($expectedBlock, LoadSkillsTool::renderCatalogBlock($this->catalog(), topLevelOnly: true, skillNamesOfTheLoadedSkillsBlock: ['Shop.Refunds']), 'a loaded skill the block does not list marks nothing');
        self::assertSame('', LoadSkillsTool::renderCatalogBlock(new AISkills(), skillNamesOfTheLoadedSkillsBlock: ['Shop.Orders']));
    }

    public function testTheToolSchemaIsUnchanged(): void
    {
        $toolSchema = LoadSkillsTool::forCatalog($this->catalog(), ['Shop.Orders'])->toOpenAiToolSchema();

        self::assertSame(['Shop.Catalog', 'Shop.Refunds'], $toolSchema['function']['parameters']['properties']['skill_names']['items']['enum']);
        self::assertStringNotContainsString('LOADED', (string)json_encode($toolSchema));
    }

    /**
     * The prompt build passes the skills of its own loaded-skills block: the catalog segment marks exactly those, and a
     * build that renders no loaded-skills block marks nothing.
     */
    public function testThePromptBuildMarksTheSkillsOfItsLoadedSkillsBlock(): void
    {
        $agentModelInput = new AgentModelInput();
        $agentModelInput->systemPrompt = 'BASE';
        $agentModelInput->offeredSkillCatalog = $this->catalog();
        self::assertStringNotContainsString('LOADED', $this->segmentText($agentModelInput, AgentContextSegmentType::SKILL_CATALOG), 'no skill loaded');

        $orders = $this->orders();
        $orders->instruction = 'Ship an order only after the owner confirmed it.';
        $loadedAISkills = new AISkills();
        $loadedAISkills->add($orders);
        $agentModelInput->loadedAISkills = $loadedAISkills;
        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Shop.Orders' => ['name' => 'Shop.Orders', 'type' => LoadedCapability::TYPE_SKILL],
        ]);

        self::assertStringContainsString('Currently loaded: Shop.Orders', $this->segmentText($agentModelInput, AgentContextSegmentType::LOADED_SKILLS));
        $catalogText = $this->segmentText($agentModelInput, AgentContextSegmentType::SKILL_CATALOG);
        self::assertStringStartsWith(LoadSkillsTool::CATALOG_HEADER, $catalogText);
        self::assertStringContainsString('- Shop.Orders — LOADED, its tools are in your tool list — Orders of the shop', $catalogText);
        self::assertStringContainsString("- Shop.Catalog — Products, prices and stock of the shop", $catalogText);
        self::assertSame(1, substr_count($catalogText, '— LOADED, its tools'));
    }

    /**
     * Append-only loading: the system content of an epoch carries the skills loaded at its start, and the catalog marks
     * those; a skill loaded later in the epoch stays unmarked, is announced by the appended item, and the header names
     * that announcement as a sign of a loaded skill.
     */
    public function testAnAppendOnlyEpochMarksItsBaseSetAndTheHeaderNamesTheLaterAnnouncement(): void
    {
        $orders = $this->orders();
        $orders->instruction = 'Ship an order only after the owner confirmed it.';
        $shopCatalog = $this->skill('Shop.Catalog', 'Products, prices and stock of the shop');
        $shopCatalog->instruction = 'Change a price only after the owner named it.';
        $baseAISkills = new AISkills();
        $baseAISkills->add($orders);
        $prefixEpoch = new AgentPrefixEpoch();
        $prefixEpoch->baseAISkills = $baseAISkills;
        $prefixEpoch->baseCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Shop.Orders' => ['name' => 'Shop.Orders', 'type' => LoadedCapability::TYPE_SKILL],
        ]);
        // the epoch began with the orders skill; the catalog skill was loaded later in the epoch
        $loadedAISkills = new AISkills();
        $loadedAISkills->add($orders);
        $loadedAISkills->add($shopCatalog);
        $agentModelInput = new AgentModelInput();
        $agentModelInput->systemPrompt = 'BASE';
        $agentModelInput->offeredSkillCatalog = $this->catalog();
        $agentModelInput->prefixEpoch = $prefixEpoch;
        $agentModelInput->loadedAISkills = $loadedAISkills;
        $agentModelInput->loadedCapabilities = LoadedCapabilities::fromCanonicalEntries([
            'Shop.Orders' => ['name' => 'Shop.Orders', 'type' => LoadedCapability::TYPE_SKILL],
            'Shop.Catalog' => ['name' => 'Shop.Catalog', 'type' => LoadedCapability::TYPE_SKILL],
        ]);

        self::assertSame(
            LoadSkillsTool::CATALOG_HEADER
                . "- Shop.Catalog — Products, prices and stock of the shop\n"
                . '- Shop.Orders — LOADED, its tools are in your tool list — Orders of the shop: list, ship, refund',
            $this->segmentText($agentModelInput, AgentContextSegmentType::SKILL_CATALOG),
            'the epoch\'s base set is marked, the skill loaded later is not'
        );
        self::assertStringContainsString('or announced as loaded later in this conversation is loaded', LoadSkillsTool::CATALOG_HEADER);
        // the announcement the header names: the item appended for a skill loaded inside the epoch
        self::assertStringStartsWith('# Skills loaded now', AgentAppendOnlyCall::HEADER_SKILLS_LOADED);
        self::assertStringStartsWith('# Skills loaded now', AgentAppendOnlyCall::HEADER_SKILLS_LOADED_TOOLS_LISTED);
    }

    protected function segmentText(AgentModelInput $agentModelInput, AgentContextSegmentType $segmentType): string
    {
        foreach ($agentModelInput->buildSystemSegments() as $segment) {
            /** @var AgentContextSegment $segment */
            if ($segment->type === $segmentType) {
                return $segment->text;
            }
        }
        return '';
    }

    protected function catalog(): AISkills
    {
        $catalog = new AISkills();
        $shopCatalog = $this->skill('Shop.Catalog', 'Products, prices and stock of the shop');
        $catalog->add($shopCatalog);
        $orders = $this->orders();
        $catalog->add($orders);
        $refunds = $this->skill('Shop.Refunds', 'Refunds of an order');
        $refunds->isSubSkill = true;
        $catalog->add($refunds);
        return $catalog;
    }

    protected function orders(): AISkill
    {
        $orders = $this->skill('Shop.Orders', 'Orders of the shop: list, ship, refund');
        $orders->subSkillNames = ['Shop.Refunds'];
        return $orders;
    }

    protected function skill(string $name, string $description): AISkill
    {
        $skill = new AISkill();
        $skill->name = $name;
        $skill->description = $description;
        return $skill;
    }
}
