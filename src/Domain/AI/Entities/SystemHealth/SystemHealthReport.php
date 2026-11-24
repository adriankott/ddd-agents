<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\SystemHealth;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * The health report of the platform: the package's sections, then the application's, each once.
 *
 * @property SystemHealthSection[] $elements
 * @method SystemHealthSection[] getElements()
 * @method SystemHealthSection|null first()
 * @method SystemHealthSection|null getByUniqueKey(string $uniqueKey)
 */
class SystemHealthReport extends ObjectSet
{
    /** @return string[] The names of the sections that need a person */
    public function getNamesOfSectionsThatNeedAttention(): array
    {
        $sectionNames = [];
        foreach ($this->getElements() as $systemHealthSection) {
            if ($systemHealthSection->needsAttention()) {
                $sectionNames[] = $systemHealthSection->name;
            }
        }
        return $sectionNames;
    }

    public function getSection(string $sectionName): ?SystemHealthSection
    {
        return $this->getByUniqueKey(SystemHealthSection::uniqueKeyStatic($sectionName));
    }
}
