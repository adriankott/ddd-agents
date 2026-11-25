<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Installation;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The findings of an installation check, in the order the checks found them
 * ({@see \DDD\Domain\AI\Services\Installation\AgentInstallationCheckService}).
 *
 * @property AgentInstallationFinding[] $elements
 * @method AgentInstallationFinding[] getElements()
 * @method AgentInstallationFinding|null getByUniqueKey(string $uniqueKey)
 */
class AgentInstallationFindings extends ObjectSet
{
    /** Adds one finding (a shorthand of {@see AgentInstallationFinding::create()}) */
    public function addFinding(string $checkName, string $severity, string $subject, string $message, ?string $hint = null): void
    {
        $agentInstallationFinding = AgentInstallationFinding::create($checkName, $severity, $subject, $message, $hint);
        $this->add($agentInstallationFinding);
    }

    /** Adds every finding of another set, in its order */
    public function addFindingsOf(AgentInstallationFindings $agentInstallationFindings): void
    {
        foreach ($agentInstallationFindings->getElements() as $agentInstallationFinding) {
            $this->add($agentInstallationFinding);
        }
    }

    public function hasBlockingFindings(): bool
    {
        return $this->countBlockingFindings() > 0;
    }

    public function countBlockingFindings(): int
    {
        $blockingFindingsCount = 0;
        foreach ($this->getElements() as $agentInstallationFinding) {
            if ($agentInstallationFinding->isBlocking()) {
                $blockingFindingsCount++;
            }
        }
        return $blockingFindingsCount;
    }

    public function countWarnings(): int
    {
        return $this->count() - $this->countBlockingFindings();
    }

    /**
     * The findings of one check.
     *
     * @param string $checkName
     * @return AgentInstallationFindings
     */
    public function findFindingsOfCheck(string $checkName): AgentInstallationFindings
    {
        $findingsOfCheck = AgentInstallationFindings::newInstance();
        foreach ($this->getElements() as $agentInstallationFinding) {
            if ($agentInstallationFinding->checkName === $checkName) {
                $findingsOfCheck->add($agentInstallationFinding);
            }
        }
        return $findingsOfCheck;
    }

    /**
     * The subjects of the findings, in order (a test reads them).
     *
     * @return string[]
     */
    public function getSubjects(): array
    {
        $subjects = [];
        foreach ($this->getElements() as $agentInstallationFinding) {
            $subjects[] = $agentInstallationFinding->subject;
        }
        return $subjects;
    }
}
