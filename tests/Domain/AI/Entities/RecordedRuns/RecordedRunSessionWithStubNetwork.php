<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Entities\RecordedRuns;

use DDD\Domain\AI\Entities\RecordedRuns\AgentRecordedRunSession;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusTransportRecording;

/**
 * An application's session class at the path of the package's: a recording passes its calls to the stub network
 * ({@see StubModelNetworkHandler}) instead of the real one; a replay plays as the package's session does.
 */
class RecordedRunSessionWithStubNetwork extends AgentRecordedRunSession
{
    /** The network every recording session of this class records against. */
    public static ?StubModelNetworkHandler $stubModelNetworkHandler = null;

    protected function buildTransportRecording(): ArgusTransportRecording
    {
        if ($this->isReplay()) {
            return parent::buildTransportRecording();
        }
        return ArgusTransportRecording::forRecording(self::$stubModelNetworkHandler);
    }
}
