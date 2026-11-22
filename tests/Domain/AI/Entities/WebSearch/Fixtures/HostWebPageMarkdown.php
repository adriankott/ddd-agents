<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\WebSearch\Fixtures;

use DDD\Domain\AI\Entities\WebSearch\WebPageMarkdown;

/** An application's page read with its own line below the header and its own sentence after a failure */
class HostWebPageMarkdown extends WebPageMarkdown
{
    public function buildPlatformLinesBelowHeader(): string
    {
        return "Platform-verified social profile URLs on this page: https://social.example/acme\n";
    }

    public function buildSentenceAfterSearchResultsPageRefusal(): string
    {
        return ' Read the profile\'s stored domain instead.';
    }

    public function buildContinueWithoutThePageSentence(bool $noToolCanReachTheAddress): string
    {
        return 'Continue with the facts already on file.';
    }
}
