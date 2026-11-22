<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Evals;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * One file a case puts into its own workspace before the agent runs: an entry of the case's `scratchFiles`
 * (`{"path": "data/orders.csv", "content": "…"}`, or `contentBase64` for a binary file). The path is relative to the
 * workspace root; the application's workspace writes it ({@see \DDD\Domain\AI\Services\Eval\AgentEvalRunHooksService::prepareWorkspaceOfCase()}).
 *
 * @phpstan-consistent-constructor
 */
class AgentEvalScratchFile extends ValueObject
{
    /** @var string The path inside the workspace, relative to its root */
    public string $path = '';

    /** @var string The file's content (bytes; decoded from `contentBase64` when the fixture gave that) */
    public string $content = '';

    /** The file of one `scratchFiles` entry; null for an entry without a path or with an undecodable `contentBase64`. */
    public static function fromFixtureEntry(mixed $fixtureEntry): ?static
    {
        if (!is_array($fixtureEntry) || !is_string($fixtureEntry['path'] ?? null) || trim($fixtureEntry['path']) === '') {
            return null;
        }
        $agentEvalScratchFile = static::newInstance();
        $agentEvalScratchFile->path = ltrim(trim($fixtureEntry['path']), '/');
        if (is_string($fixtureEntry['contentBase64'] ?? null)) {
            $decodedContent = base64_decode($fixtureEntry['contentBase64'], true);
            if ($decodedContent === false) {
                return null;
            }
            $agentEvalScratchFile->content = $decodedContent;
            return $agentEvalScratchFile;
        }
        $agentEvalScratchFile->content = is_string($fixtureEntry['content'] ?? null) ? $fixtureEntry['content'] : '';
        return $agentEvalScratchFile;
    }

    /** One file per path. */
    public function uniqueKey(): string
    {
        return static::uniqueKeyStatic($this->path);
    }
}
