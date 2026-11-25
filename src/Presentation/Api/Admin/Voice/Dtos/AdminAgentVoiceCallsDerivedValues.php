<?php

declare(strict_types=1);

namespace DDD\Presentation\Api\Admin\Voice\Dtos;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * What the server derives for the calls of the admin list ({@see AdminAgentVoiceCallDerivedValues}): one entry per
 * call, in the order of the calls. An entry is held under the id of its call, so a call has one entry and a screen
 * finds it by the call ({@see AdminAgentVoiceCallDerivedValues::uniqueKey()}).
 *
 * @property AdminAgentVoiceCallDerivedValues[] $elements
 * @method AdminAgentVoiceCallDerivedValues[] getElements()
 * @method AdminAgentVoiceCallDerivedValues|null first()
 * @method AdminAgentVoiceCallDerivedValues|null getByUniqueKey(string $uniqueKey)
 * @phpstan-consistent-constructor
 */
class AdminAgentVoiceCallsDerivedValues extends ObjectSet
{
}
