<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Symfony\Commands\Agents\RunReport;

use DDD\Symfony\Commands\Agents\AgentRunReportCommand;
use DDD\Symfony\Commands\Agents\RunReport\AgentRunReportVerdict;
use DDD\Symfony\Commands\Agents\RunReport\AgentRunReportVerdicts;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The judge verdicts are read from the evaluation log that names the conversation: the verdict line, the score, the
 * case and the VIOLATED / PARTIAL findings; a log of a conversation not asked for and a log without a verdict line
 * give none; a later log of the same conversation replaces the verdict read before.
 */
class AgentRunReportCommandVerdictsTest extends UnitTestCase
{
    public function testTheVerdictsAreReadFromTheLogThatNamesTheConversation(): void
    {
        $directory = sys_get_temp_dir() . '/run-report-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/v7-case.log', "  · purposed conversation started (id 4711) — agent greeted\n  · driver loop ended — judging\n  ✘ FAIL 63% notes.reply.short — 6t/DONE — replies=5\n      VIOLATED (3) REACH — the owner said in person\n      PARTIAL (6) FORM — two questions\n");
        file_put_contents($directory . '/v7-other.log', "conversation started (id 4712)\n  ✔ PASS 92% notes.multi — 7t/DONE\n");
        file_put_contents($directory . '/v7-unjudged.log', "conversation started (id 4713)\n  · driver loop ended\n");
        try {
            $verdicts = (new AgentRunReportCommand())->readVerdictsFromLogs($directory, [4711, 4712, 4713]);
        } finally {
            array_map('unlink', glob($directory . '/*.log') ?: []);
            rmdir($directory);
        }

        $failedVerdict = $verdicts->getByConversationId(4711);
        self::assertNotNull($failedVerdict);
        self::assertSame('FAIL', $failedVerdict->verdict);
        self::assertSame(63, $failedVerdict->scorePercent);
        self::assertSame('notes.reply.short', $failedVerdict->caseId);
        self::assertSame(['VIOLATED (3) REACH — the owner said in person', 'PARTIAL (6) FORM — two questions'], $failedVerdict->findings);
        self::assertSame('FAIL 63 %', $failedVerdict->getJudgeCellText());
        self::assertFalse($failedVerdict->isPass());
        self::assertSame('PASS', $verdicts->getByConversationId(4712)?->verdict);
        self::assertTrue($verdicts->getByConversationId(4712)?->isPass());
        self::assertNull($verdicts->getByConversationId(4713), 'a log without a verdict line gives none');
        self::assertCount(2, $verdicts->getElements());
    }

    public function testALaterVerdictOfTheSameConversationReplacesTheOneReadBefore(): void
    {
        $verdicts = AgentRunReportVerdicts::newInstance();
        $verdicts->addOrReplace(AgentRunReportVerdict::forRun(4711, 'first.case', 'FAIL', 40, []));
        $verdicts->addOrReplace(AgentRunReportVerdict::forRun(4712, 'other.case', 'PASS', 90, []));
        $verdicts->addOrReplace(AgentRunReportVerdict::forRun(4711, 'second.case', 'PASS', 80, ['PARTIAL x']));

        self::assertCount(2, $verdicts->getElements());
        self::assertSame('second.case', $verdicts->getByConversationId(4711)?->caseId);
        self::assertSame('', AgentRunReportVerdict::none()->getJudgeCellText());
        self::assertFalse(AgentRunReportVerdict::none()->hasVerdict());
    }

    public function testAFindingKeepsItsMarkerAndAtMostTwoHundredSixtyCharacters(): void
    {
        $directory = sys_get_temp_dir() . '/run-report-' . uniqid();
        mkdir($directory);
        file_put_contents($directory . '/long.log', "conversation started (id 5)\nFAIL 10% long.case\n  VIOLATED " . str_repeat('ä', 300) . "\n");
        try {
            $verdicts = (new AgentRunReportCommand())->readVerdictsFromLogs($directory, [5]);
        } finally {
            array_map('unlink', glob($directory . '/*.log') ?: []);
            rmdir($directory);
        }

        self::assertSame(['VIOLATED ' . str_repeat('ä', 259)], $verdicts->getByConversationId(5)?->findings);
    }
}
