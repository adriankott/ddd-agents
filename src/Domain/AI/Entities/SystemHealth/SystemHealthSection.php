<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\SystemHealth;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One part of the platform as the health report sees it ({@see \DDD\Domain\AI\Services\SystemHealth\SystemHealthService}):
 * its state, one sentence of numbers, and the findings that need a person, each on its own line. The package builds the
 * sections of Core's features (workers, crons, issues); an application adds its own through
 * {@see \DDD\Domain\AI\Services\SystemHealth\SystemHealthSectionProvider}.
 */
class SystemHealthSection extends ValueObject
{
    /** @var string The Messenger workers: queues, processes, failures */
    public const string WORKERS = 'WORKERS';

    /** @var string The cron jobs and their runner */
    public const string CRONS = 'CRONS';

    /** @var string The error groups of the issues registry */
    public const string ISSUES = 'ISSUES';

    /** @var string[] The sections of the package, in the order of a report */
    public const array PACKAGE_SECTION_NAMES = [self::WORKERS, self::CRONS, self::ISSUES];

    /** @var string A section name: upper-case letters, digits and `_` */
    public const string NAME_PATTERN = '/^[A-Z][A-Z0-9_]{1,47}$/';

    /** @var string Nothing needs a person */
    public const string STATE_OK = 'ok';

    /** @var string At least one finding needs a person */
    public const string STATE_ATTENTION = 'ATTENTION';

    /** @var string The part cannot be judged from here (its monitoring is off) */
    public const string STATE_UNKNOWN = 'unknown';

    /** @var string The section name (one of PACKAGE_SECTION_NAMES or an application's) */
    public string $name = '';

    /** @var string STATE_OK, STATE_ATTENTION or STATE_UNKNOWN */
    public string $state = self::STATE_OK;

    /** @var string The numbers of the part in one sentence, without a full stop */
    public string $summary = '';

    /** @var string[] What needs a person, one finding per line; raises the state to ATTENTION */
    public array $findings = [];

    /** @var string[] What a reader has to know to read the numbers right; does not change the state */
    public array $notes = [];

    public function __construct(string $name = '')
    {
        parent::__construct();
        $this->name = $name;
    }

    /** Whether `$sectionName` can name a section */
    public static function isValidName(string $sectionName): bool
    {
        return preg_match(static::NAME_PATTERN, $sectionName) === 1;
    }

    /** @param string $finding One line, without a full stop */
    public function addFinding(string $finding): void
    {
        $this->findings[] = $finding;
        $this->state = self::STATE_ATTENTION;
    }

    public function needsAttention(): bool
    {
        return $this->state === self::STATE_ATTENTION;
    }

    /**
     * @return string[] The section as lines: `<NAME>: <state> · <summary>`, then one `- ` line per finding and note
     */
    public function toLlmLines(): array
    {
        $lines = [$this->name . ': ' . $this->state . ($this->summary !== '' ? ' · ' . $this->summary : '')];
        foreach ($this->findings as $finding) {
            $lines[] = '- ' . $finding;
        }
        foreach ($this->notes as $note) {
            $lines[] = '- note: ' . $note;
        }
        return $lines;
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->name);
    }
}
