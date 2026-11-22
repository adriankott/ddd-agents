<?php

declare(strict_types=1);

namespace DDD\Tests\Agents\Domain\AI\Repo\Argus\WebSearch;

use DDD\Domain\AI\Repo\Argus\WebSearch\ArgusWebSearchHitList;
use DDD\Tests\Agents\Support\UnitTestCase;

/**
 * The search answer may say that the endpoint's own cache answered (`data.cached`) and which fee it charged
 * (`data.billing.feeUsd`); both are optional, an answer without them reads as before.
 */
class ArgusWebSearchHitListEndpointBillingTest extends UnitTestCase
{
    public function testTheEndpointCacheFlagAndFeeAreRead(): void
    {
        $argusWebSearchHitList = new ArgusWebSearchHitList();
        $argusWebSearchHitList->query = 'example';
        $callResponseData = json_decode('{"status":"OK","data":{"results":[{"title":"A","url":"https://example.com/a","snippet":"x"}],"cached":true,"billing":{"requests":1,"mode":"fast","feeUsd":0.005}}}');
        $argusWebSearchHitList->handleLoadResponse($callResponseData);

        self::assertTrue($argusWebSearchHitList->answeredFromEndpointCache);
        self::assertSame(0.005, $argusWebSearchHitList->endpointFeeUsd);
        self::assertCount(1, $argusWebSearchHitList->hits->getElements());
    }

    public function testAnAnswerWithoutBillingReadsAsBefore(): void
    {
        $argusWebSearchHitList = new ArgusWebSearchHitList();
        $argusWebSearchHitList->query = 'example';
        $callResponseData = json_decode('{"status":"OK","data":{"results":[{"title":"A","url":"https://example.com/a"}]}}');
        $argusWebSearchHitList->handleLoadResponse($callResponseData);

        self::assertFalse($argusWebSearchHitList->answeredFromEndpointCache);
        self::assertNull($argusWebSearchHitList->endpointFeeUsd);
        self::assertNull($argusWebSearchHitList->failureReason);
    }
}
