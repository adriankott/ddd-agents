<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\Mandates;

use DDD\Domain\AI\Entities\Mandates\AgentMandateGrantArguments;
use DDD\Domain\AI\Repo\Argus\Mandates\ArgusAgentMandateApproval;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The platform's own check of a ONE_INSTRUCTION judges the one call it was made for: the payload names the tool and its
 * summary line, right after the action class, and the check's prompt says to judge consent to THAT call. A grant the
 * model asks for (no tool) keeps the payload it had.
 */
class MandateApprovalCheckJudgesTheRequestedToolCallTest extends UnitTestCase
{
    public function testThePayloadNamesTheToolAndItsSummaryAfterTheActionClass(): void
    {
        $approval = static::instantiateWithoutConstructor(ArgusAgentMandateApproval::class);
        $approval->requestedActionClass = 'ACTION_SOCIAL_POSTS';
        $approval->requestedActionClassDescription = 'Publishing or deleting posts';
        $approval->requestedToolName = 'deletePost';
        $approval->requestedToolSummary = 'Delete a post or a draft.';
        $approval->requestedScopeType = 'ONE_INSTRUCTION';

        $payload = json_decode((string)$approval->getUserContent(), true, 8, JSON_THROW_ON_ERROR);

        self::assertSame('deletePost', $payload['requestedToolName']);
        self::assertSame('Delete a post or a draft.', $payload['requestedToolSummary']);
        self::assertSame(
            ['requestedActionClass', 'requestedActionClassDescription', 'requestedToolName', 'requestedToolSummary', 'requestedScopeType'],
            array_slice(array_keys($payload), 3, 5)
        );
    }

    public function testAGrantWithoutAToolKeepsItsPayload(): void
    {
        $approval = static::instantiateWithoutConstructor(ArgusAgentMandateApproval::class);
        $approval->requestedScopeType = 'UNLIMITED';

        $payload = json_decode((string)$approval->getUserContent(), true, 8, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('requestedToolName', $payload);
        self::assertArrayNotHasKey('requestedToolSummary', $payload);
    }

    public function testTheGrantArgumentsCarryTheTool(): void
    {
        $agentMandateGrantArguments = AgentMandateGrantArguments::build('ACTION_SOCIAL_POSTS', 'ONE_INSTRUCTION', null, null, 'deletePost', 'Delete a post or a draft.');

        self::assertSame('deletePost', $agentMandateGrantArguments->requestedToolName);
        self::assertSame('Delete a post or a draft.', $agentMandateGrantArguments->requestedToolSummary);
        self::assertNull(AgentMandateGrantArguments::build('ACTION_SOCIAL_POSTS', 'UNLIMITED', null, null)->requestedToolName);
    }

    public function testTheCheckPromptSaysToJudgeConsentToThatCall(): void
    {
        self::assertStringContainsString(
            "- When requestedToolName is set, judge consent to THAT call: the owner's words must ask for what this tool does (requestedToolSummary), not for the whole action class.\n",
            (string)file_get_contents(dirname(__DIR__, 6) . '/config/app/AI/Prompts/AI/Mandates/ApprovalCheck.md')
        );
    }
}
