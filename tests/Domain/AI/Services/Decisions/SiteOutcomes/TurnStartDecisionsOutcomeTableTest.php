<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\Agent\AgentRun;
use DDD\Domain\AI\Entities\Conversations\Agent\Decisions\AgentDecisionModelSettings;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The site × outcome table of the TURN-START sites: reply language, the skill-preload roots pass, the sub-skill child of
 * a skill loaded in an earlier turn, reasoning effort, plan dynamics and the owner-message injection check — each alone
 * in the batch and all of them folded, under every outcome of {@see DecisionSiteOutcomeTestCase::OUTCOMES}.
 *
 * A row runs `AgentTurnStartDecisionBatchService::decideTurnStart()` as it is (the input order, the one settled batch,
 * the apply order, the request events) with the sites' INPUTS given by the row and their APPLICATIONS run as they are.
 * Its cells ({@see TurnStartDecisionsOutcomeTableCells}): the requests sent (label, answer mode), the breaker trip and
 * close requests, the decision card per site (stamped `decidedBy`, applied or not), the working-state slices written,
 * the turn events (kind, site, the payload's `decidedBy`).
 *
 * What the table pins, one rule each:
 * - our own gate's refusal stamps every site `fallback:refused` — slice, card and events as on a transport failure, the
 *   injection check's card included;
 * - only a PARSABLE answer of the primary request or of a sub-skill child asks to close the breaker (an unparsable or a
 *   400 answer leaves it); the injection check never closes it, nor does a batch whose sibling failed at the transport
 *   level;
 * - the injection check maps our own error and a 400 to `fallback:unparsable`, an open breaker to `fallback:breaker`
 *   with no request sent;
 * - a language fallback whose recency chain lands on the conversation's anchor is stamped `anchor` and APPLIED under
 *   `on`; under `shadow` the fallback is not applied; the other sites keep the batch's `fallback:*`;
 * - the folded request answers for its own sites: a child that failed leaves the primary's answer standing, a primary
 *   that failed leaves a child's answer standing.
 */
class TurnStartDecisionsOutcomeTableTest extends DecisionSiteOutcomeTestCase
{
    /** @var string[] the sites the folded rows carry */
    public const array ALL_TURN_START_SITES = ['replyLanguage', 'skillPreload', 'loadedSkillSubSkills', 'reasoningEffort', 'planDynamics', 'ownerMessageInjection'];

    /**
     * @return array<string, array{0: string[], 1: string, 2: array<string, string>, 3: ?string, 4: array<string, float|string>}>
     *         sites, outcome, mode per request label, the site in `shadow` (null = all `on`), scripted answers
     */
    public static function turnStartRows(): array
    {
        $rows = [];
        foreach (self::ALL_TURN_START_SITES as $site) {
            foreach (self::OUTCOMES as $outcome) {
                $rows[$site . ' × ' . $outcome] = [[$site], $outcome, [], null, []];
            }
        }
        foreach (self::OUTCOMES as $outcome) {
            $rows['folded × ' . $outcome] = [self::ALL_TURN_START_SITES, $outcome, [], null, []];
        }
        $rows['folded × the sub-skill child times out, the primary answers'] = [self::ALL_TURN_START_SITES, self::OUTCOME_USABLE, ['subSkill:' . self::ROOT_SKILL => DecisionModelNetworkDouble::MODE_TIMEOUT], null, []];
        $rows['folded × the injection child times out, the primary answers'] = [self::ALL_TURN_START_SITES, self::OUTCOME_USABLE, ['ownerMessageInjection' => DecisionModelNetworkDouble::MODE_TIMEOUT], null, []];
        $rows['folded × only the primary fails at the transport level'] = [self::ALL_TURN_START_SITES, self::OUTCOME_USABLE, ['primary' => DecisionModelNetworkDouble::MODE_HTTP_503], null, []];
        $rows['ownerMessageInjection × usable, the gate blocks under on'] = [['ownerMessageInjection'], self::OUTCOME_USABLE, [], null, ['gate_passes_message' => 0.1]];
        $rows['ownerMessageInjection × usable, the gate blocks under shadow'] = [['ownerMessageInjection'], self::OUTCOME_USABLE, [], AgentDecisionModelSettings::SITE_OWNER_MESSAGE_INJECTION, ['gate_passes_message' => 0.1]];
        $rows['replyLanguage × usable under shadow'] = [['replyLanguage'], self::OUTCOME_USABLE, [], AgentDecisionModelSettings::SITE_REPLY_LANGUAGE, []];
        $rows['replyLanguage × transport under shadow (the anchor is not applied)'] = [['replyLanguage'], self::OUTCOME_TRANSPORT, [], AgentDecisionModelSettings::SITE_REPLY_LANGUAGE, []];
        $rows['replyLanguage × usable, the owner asks for English'] = [['replyLanguage'], self::OUTCOME_USABLE, [], null, ['requested_language' => 'en']];
        $rows['reasoningEffort × usable, the owner disputes a claim (high)'] = [['reasoningEffort'], self::OUTCOME_USABLE, [], null, ['disputes_what_the_assistant_claims' => 0.9]];
        $rows['planDynamics × usable, a plan is required'] = [['planDynamics'], self::OUTCOME_USABLE, [], null, ['needs_working_plan' => 0.95]];
        return $rows;
    }

    /**
     * @param string[] $sites
     * @param array<string, string> $modeByLabel
     * @param array<string, float|string> $scriptedAnswers
     */
    #[DataProvider('turnStartRows')]
    public function testTheTurnStartRow(array $sites, string $outcome, array $modeByLabel, ?string $shadowSite, array $scriptedAnswers): void
    {
        $rowName = (string)$this->dataName();
        if ($shadowSite !== null) {
            $this->useSiteInShadow($shadowSite);
        }
        $this->scriptOutcome($outcome);
        $this->decisionModelDouble->modeByLabel = $modeByLabel;
        $this->decisionModelDouble->answersByQuestion = $scriptedAnswers + $this->decisionModelDouble->answersByQuestion;
        $turnStartBatchService = $this->turnStartBatchService($sites);

        $turnStartBatchService->decideTurnStart(AgentRun::forTurnEntry(), $this->ownerTurnConversation(), null);

        self::assertSame(TurnStartDecisionsOutcomeTableCells::CELLS[$rowName] ?? ['<not pinned>'], $this->summarizeWorldLog(), $rowName);
    }
}
