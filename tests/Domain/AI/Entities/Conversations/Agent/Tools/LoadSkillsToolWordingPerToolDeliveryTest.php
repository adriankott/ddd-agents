<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\Conversations\Agent\Tools;

use DDD\Domain\AI\Entities\Conversations\Agent\Tools\LoadSkillsTool;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Entities\Skills\AISkills;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The `load_skills` texts per tool delivery: the native wording ("in your tool list", "directly") is byte-identical to
 * the wording without the delivery argument; under `call_tool` the texts say the tools are listed in this conversation
 * and are called through `call_tool` — the answers, the catalog header and loaded mark, and the tool's description.
 */
class LoadSkillsToolWordingPerToolDeliveryTest extends UnitTestCase
{
    protected const array LOADED_SKILL_NAMES = ['Shop.Catalog'];

    protected const array CALLABLE_TOOL_NAMES = ['getProducts', 'updateProduct'];

    public function testHowAToolIsCalledPerDelivery(): void
    {
        self::assertSame('directly', LoadSkillsTool::renderHowAToolIsCalled(false));
        self::assertSame('through `call_tool`', LoadSkillsTool::renderHowAToolIsCalled(true));
    }

    public function testTheNativeAnswersAreUnchanged(): void
    {
        self::assertSame(
            LoadSkillsTool::buildResultOfCallNamingNoSkill(self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES),
            LoadSkillsTool::buildResultOfCallNamingNoSkill(self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES, false)
        );
        self::assertSame(
            LoadSkillsTool::buildResultOfRepeatedCall(2, self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES),
            LoadSkillsTool::buildResultOfRepeatedCall(2, self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES, false)
        );
        self::assertSame(
            'NOT executed: you have called `load_skills` with these exact arguments 4 times and it loads nothing more. Loaded right '
                . 'now: Shop.Catalog — their tools are ALREADY in your tool list (getProducts, updateProduct). Your next step has no '
                . 'tools: write your answer now from what this conversation already holds. If the request needed one of these tools '
                . 'and you did not call it, say plainly that this part is not done yet — never that you lack access or that it is unavailable.',
            LoadSkillsTool::buildResultOfRepeatedCallBeforeAStepWithoutTools(4, self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES)
        );
    }

    public function testTheCallNamingNoSkillIsAnsweredWithTheCallToolWording(): void
    {
        self::assertSame(
            'Nothing was loaded: `skill_names` named no skill. Loaded right now: Shop.Catalog — their tools are ALREADY listed in this '
                . 'conversation (getProducts, updateProduct). Call the tool the request needs through `call_tool` NOW. `load_skills` '
                . 'takes only a skill that is not loaded yet, named exactly as "Available skills" or the sub-skill list of a loaded skill '
                . 'states it; never call it with an empty list.',
            LoadSkillsTool::buildResultOfCallNamingNoSkill(self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES, true)
        );
        self::assertSame(LoadSkillsTool::RESULT_WITHOUT_SKILL_NAMES, LoadSkillsTool::buildResultOfCallNamingNoSkill([], [], true));
    }

    public function testTheRepeatedCallIsAnsweredWithTheCallToolWording(): void
    {
        self::assertSame(
            'NOT executed: you have called `load_skills` with these exact arguments 3 times and it loads nothing more. Loaded right '
                . 'now: Shop.Catalog — their tools are ALREADY listed in this conversation (getProducts, updateProduct). Call the tool '
                . 'the request needs through `call_tool` NOW, or answer if the request needs no tool. Do not repeat this `load_skills` call.',
            LoadSkillsTool::buildResultOfRepeatedCall(3, self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES, true)
        );
        self::assertStringContainsString(
            'their tools are ALREADY listed in this conversation (getProducts, updateProduct). Your next step has no tools',
            (string)LoadSkillsTool::buildResultOfRepeatedCallBeforeAStepWithoutTools(4, self::LOADED_SKILL_NAMES, self::CALLABLE_TOOL_NAMES, true)
        );
        self::assertNull(LoadSkillsTool::buildResultOfRepeatedCall(3, [], [], true));
    }

    public function testTheCatalogBlockMarksLoadedSkillsWithTheCallToolWording(): void
    {
        $callToolBlock = LoadSkillsTool::renderCatalogBlock($this->buildCatalog(), skillNamesOfTheLoadedSkillsBlock: ['Shop.Orders'], toolsAreCalledThroughCallTool: true);

        self::assertSame(
            LoadSkillsTool::CATALOG_HEADER_CALL_TOOL
                . "- Shop.Catalog — Products of the shop\n"
                . '- Shop.Orders — LOADED, its tools are listed in this conversation — Orders of the shop',
            $callToolBlock
        );
        self::assertStringContainsString('its tools are listed in this conversation — call them through `call_tool`, never load that skill again.', LoadSkillsTool::CATALOG_HEADER_CALL_TOOL);
        self::assertStringNotContainsString('in your tool list', $callToolBlock);

        self::assertSame(
            LoadSkillsTool::CATALOG_HEADER . "- Shop.Catalog — Products of the shop\n- Shop.Orders — LOADED, its tools are in your tool list — Orders of the shop",
            LoadSkillsTool::renderCatalogBlock($this->buildCatalog(), skillNamesOfTheLoadedSkillsBlock: ['Shop.Orders']),
            'the native block is unchanged'
        );
    }

    public function testTheCatalogBlockWithoutLoadedMarksUsesItsCallToolHeader(): void
    {
        self::assertSame(
            LoadSkillsTool::CATALOG_HEADER_WITHOUT_LOADED_MARKS_CALL_TOOL . "- Shop.Catalog — Products of the shop\n- Shop.Orders — Orders of the shop",
            LoadSkillsTool::renderCatalogBlock($this->buildCatalog(), toolsAreCalledThroughCallTool: true)
        );
        self::assertSame(
            LoadSkillsTool::CATALOG_HEADER_WITHOUT_LOADED_MARKS . "- Shop.Catalog — Products of the shop\n- Shop.Orders — Orders of the shop",
            LoadSkillsTool::renderCatalogBlock($this->buildCatalog())
        );
        self::assertSame('', LoadSkillsTool::renderCatalogBlock(new AISkills(), toolsAreCalledThroughCallTool: true));
    }

    public function testTheLoadedLinePerDelivery(): void
    {
        $ordersSkill = $this->buildSkill('Shop.Orders', 'Orders of the shop');

        self::assertSame('- Shop.Orders — LOADED, its tools are in your tool list — Orders of the shop', LoadSkillsTool::renderCatalogLineOfLoadedSkill($ordersSkill));
        self::assertSame(
            '- Shop.Orders — LOADED, its tools are listed in this conversation — Orders of the shop',
            LoadSkillsTool::renderCatalogLineOfLoadedSkill($ordersSkill, toolsAreCalledThroughCallTool: true)
        );
    }

    public function testTheToolDescriptionPerDelivery(): void
    {
        $nativeDescription = LoadSkillsTool::forCatalog($this->buildCatalog())->toOpenAiToolSchema()['function']['description'];
        $callToolDescription = LoadSkillsTool::forCatalog($this->buildCatalog(), [], true)->toOpenAiToolSchema()['function']['description'];

        self::assertStringEndsWith('A skill already loaded is never loaded again — its tools are in your tool list: call them.', $nativeDescription);
        self::assertStringEndsWith(
            'A skill already loaded is never loaded again — its tools are listed in this conversation: call them through `call_tool`.',
            $callToolDescription
        );
        self::assertSame(
            LoadSkillsTool::forCatalog($this->buildCatalog(), ['Shop.Orders'])->toOpenAiToolSchema()['function']['parameters'],
            LoadSkillsTool::forCatalog($this->buildCatalog(), ['Shop.Orders'], true)->toOpenAiToolSchema()['function']['parameters'],
            'only the description differs'
        );
    }

    protected function buildCatalog(): AISkills
    {
        $catalog = new AISkills();
        $catalogSkill = $this->buildSkill('Shop.Catalog', 'Products of the shop');
        $catalog->add($catalogSkill);
        $ordersSkill = $this->buildSkill('Shop.Orders', 'Orders of the shop');
        $catalog->add($ordersSkill);
        return $catalog;
    }

    protected function buildSkill(string $name, string $description): AISkill
    {
        $skill = new AISkill();
        $skill->name = $name;
        $skill->description = $description;
        return $skill;
    }
}
