<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Memories;

use DDD\Domain\AI\Repo\DB\Conversations\Memories\DBAgentMemories;
use DDD\Domain\AI\Services\Conversations\Memories\AgentMemoryStoreService;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\LlmMarkdownRenderable;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptions;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Infrastructure\Base\DateTime\DateTime;

/**
 * Memories of the package's memory store ({@see AgentMemory}).
 *
 * @property AgentMemory[] $elements
 * @method AgentMemory|null first()
 * @method AgentMemory[] getElements()
 * @method static AgentMemoryStoreService getService()
 */
#[QueryOptions]
#[LazyLoadRepo(LazyLoadRepo::DB, DBAgentMemories::class)]
class AgentMemories extends EntitySet implements LlmMarkdownRenderable
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    public const string SERVICE_NAME = AgentMemoryStoreService::class;

    /** @var int The most directives injected into a conversation (newest first) */
    public const int MAX_DIRECTIVES_IN_CONTEXT = 30;

    /** @var int The longest directive injected; a longer one is cut */
    public const int MAX_DIRECTIVE_CHARS_IN_CONTEXT = 1000;

    /** @var string The header of the standing-directives block the model reads */
    public const string DIRECTIVES_BLOCK_HEADER = '# Standing instructions of the owner — always follow them';

    /**
     * The currently valid directives of the set.
     *
     * @return AgentMemories
     */
    public function getCurrentlyValidDirectives(): AgentMemories
    {
        $currentlyValidDirectives = AgentMemories::newInstance();
        foreach ($this->getElements() as $agentMemory) {
            if ($agentMemory->isCurrentlyValid() && $agentMemory->memoryType === AgentMemory::MEMORY_TYPE_DIRECTIVE) {
                $currentlyValidDirectives->add($agentMemory);
            }
        }
        return $currentlyValidDirectives;
    }

    /**
     * The newest {@see AgentMemory::$validFrom} of the set's current directives, or null.
     *
     * @return DateTime|null
     */
    public function findNewestValidFromOfCurrentDirectives(): ?DateTime
    {
        $newestValidFrom = null;
        foreach ($this->getCurrentlyValidDirectives()->getElements() as $directive) {
            if (isset($directive->validFrom) && ($newestValidFrom === null || $directive->validFrom > $newestValidFrom)) {
                $newestValidFrom = $directive->validFrom;
            }
        }
        return $newestValidFrom;
    }

    /**
     * The standing-directives block of the model input: its own leading separator and header, one line per current
     * directive, at most {@see MAX_DIRECTIVES_IN_CONTEXT}, each cut to {@see MAX_DIRECTIVE_CHARS_IN_CONTEXT}; empty when
     * there is no directive.
     *
     * @return string
     */
    public function toDirectivesBlock(): string
    {
        $directiveLines = [];
        foreach ($this->getCurrentlyValidDirectives()->getElements() as $directive) {
            $directiveContent = trim($directive->content);
            if ($directiveContent === '') {
                continue;
            }
            if (mb_strlen($directiveContent) > self::MAX_DIRECTIVE_CHARS_IN_CONTEXT) {
                $directiveContent = rtrim(mb_substr($directiveContent, 0, self::MAX_DIRECTIVE_CHARS_IN_CONTEXT)) . '…';
            }
            $directiveLines[] = '- ' . $directiveContent;
            if (count($directiveLines) >= self::MAX_DIRECTIVES_IN_CONTEXT) {
                break;
            }
        }
        if ($directiveLines === []) {
            return '';
        }
        return "\n\n" . self::DIRECTIVES_BLOCK_HEADER . "\n" . implode("\n", $directiveLines);
    }

    public function toLlmMarkdown(): string
    {
        $memoryLines = [];
        foreach ($this->getElements() as $agentMemory) {
            $memoryLines[] = $agentMemory->toLlmMarkdown();
        }
        return $memoryLines === [] ? '(no memories)' : implode("\n", $memoryLines);
    }
}
