<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Eval;

use DDD\Domain\AI\Entities\ClientTools\AgentClientToolRequest;
use DDD\Domain\AI\Services\Eval\AgentScriptedUIClientService;

/** The scripted client over a catalogue held in memory: what a `listScreens` request answers */
class CatalogScriptedUIClientServiceDouble extends AgentScriptedUIClientService
{
    /** @var mixed[] The fixture `catalog` */
    public array $catalog = [];

    public function listScreens(string $searchText): string
    {
        $request = new AgentClientToolRequest();
        $request->argumentsJson = (string)json_encode(['searchText' => $searchText]);
        return (string)$this->answerListTargets($request)[1];
    }

    /** @return mixed[] */
    protected function loadFixture(string $fixtureName): array
    {
        return $this->catalog;
    }
}
