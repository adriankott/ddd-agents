<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Services\Decisions\SiteOutcomes;

use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Services\Conversations\AIConversationsService;
use DDD\Domain\Base\Entities\Entity;

/**
 * The conversations service of the decision sites with its one working-state writer recorded instead of run: the real
 * slice writer builds the slice and hands it here. The `decisions` slice is logged as its NEWEST card — the card one
 * decision call appended — as `card <site> <decidedBy> applied|not applied`; every other key as `slice <key>`, its
 * value kept in {@see $writtenSlices}. No row is read or written.
 */
class WorkingStateSliceRecordingConversationsServiceDouble extends AIConversationsService
{
    /** @var array<int, array<string, mixed>> every slice handed to the writer, in call order */
    public array $writtenSlices = [];

    public function __construct(public DecisionSiteWorldLog $worldLog)
    {
    }

    protected function writeAgentWorkingStateSliceUnderRunToken(AIConversation $conversation, array $slice, ?string $runToken): void
    {
        $this->writtenSlices[] = $slice;
        foreach ($slice as $sliceKey => $sliceValue) {
            if ($sliceKey === 'decisions' && is_array($sliceValue)) {
                $newestCard = $sliceValue === [] ? [] : (array)$sliceValue[array_key_last($sliceValue)];
                $this->worldLog->record('card ' . ($newestCard['site'] ?? '?') . ' ' . ($newestCard['decidedBy'] ?? 'null') . ' ' . (($newestCard['applied'] ?? false) === true ? 'applied' : 'not applied'));
                continue;
            }
            $this->worldLog->record('slice ' . $sliceKey . self::describeSliceValue((string)$sliceKey, $sliceValue));
        }
    }

    /**
     * The fields of a slice that carry the site's answer to the loop, as ` {json}` (empty for a slice not listed):
     * who decided and what the loop acts on.
     */
    public static function describeSliceValue(string $sliceKey, mixed $sliceValue): string
    {
        $answerFieldsBySliceKey = [
            'replyLanguageDecision' => ['decidedBy', 'languageCode', 'source'],
            'skillPreloadDecision' => ['decidedBy', 'pass', 'pickedSkillName', 'skipReason', 'admittedSkillNames'],
            'reasoningEffortDecision' => ['decidedBy', 'resolvedEffort', 'effectiveEffort', 'fallbackReason'],
            'planDynamicsDecision' => ['decidedBy', 'band'],
            'stallRecoveryDecision' => ['decidedBy', 'move', 'applied'],
            'turnClosingDecision' => ['decidedBy', 'wouldBounce', 'fallbackReason'],
        ];
        if ($sliceKey === 'securityFilteredOwnerMessageIndexes') {
            return ' ' . json_encode($sliceValue);
        }
        if (!isset($answerFieldsBySliceKey[$sliceKey]) || !is_array($sliceValue)) {
            return '';
        }
        return ' ' . json_encode(array_intersect_key($sliceValue, array_flip($answerFieldsBySliceKey[$sliceKey])), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function find(string|int|null $entityId, bool $useEntityRegistrCache = true): ?Entity
    {
        return null;
    }
}
