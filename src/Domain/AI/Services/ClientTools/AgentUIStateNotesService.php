<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Services\ClientTools;

use DDD\Domain\AI\Entities\ClientTools\AgentClientAttachment;
use DDD\Domain\AI\Entities\ClientTools\AgentClientAttachments;
use DDD\Domain\AI\Entities\ClientTools\AgentUIScreen;
use DDD\Domain\AI\Entities\ClientTools\AgentUIState;
use DDD\Domain\AI\Entities\Conversations\AIConversation;
use DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDeltaTexts;
use DDD\Domain\AI\Entities\MCPPackages\LlmRendering\LlmTextRendering;
use DDD\Domain\AI\Entities\Skills\AISkill;
use DDD\Domain\AI\Services\Skills\AISkillsService;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Base\DateTime\DateTime;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Throwable;

/**
 * The awareness of `Agent.UI`: the `[SIGNAL:ui]` state note of a conversation, the value the state delivery compares
 * on every model call ({@see \DDD\Domain\AI\Entities\Conversations\Agent\StateDelivery\AgentStateDelta::KIND_UI}). A
 * changed note is appended once and stays at its place for the rest of the state epoch; an unchanged one is not sent
 * again.
 *
 * The note is written by the server from the client's normalized UI state ({@see AgentUIState}) and the application's
 * screen catalog ({@see AgentUIScreenCatalogService}): area, record with the server's description, tab and view, the
 * count of unsaved fields and of validation messages, an open dialog. It carries no free text of the client and no
 * clock (an age that grows would be a new value on every call). Without a fresh client the note says so once.
 */
class AgentUIStateNotesService extends Service
{
    /** @var string The kind of the note */
    public const string NOTE_KIND = 'ui';

    /** @var string The note when the last client is gone */
    public const string NO_CLIENT_NOTE = '[SIGNAL:ui] No client of the user is attached since the last ui note: what it said may be old, and the UI tools answer "no client attached".';

    /** @var string The note while the client is in the background */
    public const string BACKGROUND_NOTE = '[SIGNAL:ui] The user\'s client is in the background: the last ui note may be old, and the UI tools answer "background".';

    /** @var string The first words of a screen note */
    public const string SCREEN_NOTE_LEAD = '[SIGNAL:ui] The user\'s screen now:';

    /** @var int The longest description of a record the note shows */
    public const int MAX_RECORD_DESCRIPTION_CHARS = 160;

    /**
     * The note of the conversation for a model call now: null when no client of the conversation ever reported a UI
     * state (the conversation has no UI); the no-client note when the clients that reported one are no longer fresh.
     *
     * @throws Throwable a failed read (the caller treats the value as unknown and compares nothing)
     */
    public function buildUINoteOfConversation(AIConversation $conversation): ?string
    {
        $rootConversationId = (int)($conversation->rootConversationId ?? $conversation->id ?? 0);
        if ($rootConversationId <= 0 || !$this->isUISkillBound()) {
            return null;
        }
        DDDService::instance()->deactivateEntityRightsRestrictions();
        try {
            $agentClientAttachments = $this->getAgentClientAttachmentsService()->findAttachmentsOfConversation($rootConversationId);
        } finally {
            DDDService::instance()->restoreEntityRightsRestrictionsStateSnapshot();
        }
        $hasReportedUIState = false;
        $freshAttachments = AgentClientAttachments::newInstance();
        $now = new DateTime();
        foreach ($agentClientAttachments->getElements() as $agentClientAttachment) {
            if (($agentClientAttachment->uiStateJson ?? null) === null) {
                continue;
            }
            $hasReportedUIState = true;
            if ($agentClientAttachment->isFresh($now)) {
                $freshAttachments->add($agentClientAttachment);
            }
        }
        if (!$hasReportedUIState) {
            return null;
        }
        $targetAttachment = AgentClientAttachmentsService::pickTargetAttachment($freshAttachments);
        if ($targetAttachment === null) {
            return static::NO_CLIENT_NOTE;
        }
        if (($targetAttachment->visible ?? null) === false) {
            return static::BACKGROUND_NOTE;
        }
        return $this->buildScreenNote($targetAttachment, $conversation);
    }

    /** The note of one client's UI state */
    public function buildScreenNote(AgentClientAttachment $agentClientAttachment, AIConversation $conversation): string
    {
        $agentUIState = $agentClientAttachment->getUIState() ?? new AgentUIState();
        $agentUIScreen = $this->getAgentUIScreenCatalogService()->describeScreen($agentUIState, $conversation);
        $noteParts = [$this->describeArea($agentUIScreen)];
        if ($agentUIScreen !== null && $agentUIScreen->recordId !== null && AgentUIState::isValidToken($agentUIScreen->recordId)) {
            $recordDescription = trim((string)$agentUIScreen->recordDescription);
            $noteParts[] = 'record ' . ($agentUIScreen->recordKind !== null ? LlmTextRendering::toSingleLine($agentUIScreen->recordKind) . ' ' : '')
                . '#' . $agentUIScreen->recordId
                . ($recordDescription !== '' ? ' ' . LlmTextRendering::shorten(LlmTextRendering::toSingleLine($recordDescription), static::MAX_RECORD_DESCRIPTION_CHARS) : '');
        }
        if ($agentUIState->tab !== null) {
            $noteParts[] = 'tab ' . $agentUIState->tab;
        }
        if ($agentUIState->view !== null) {
            $noteParts[] = 'view ' . $agentUIState->view;
        }
        $unsavedFieldCount = count($agentUIState->unsavedFieldReferences);
        $noteParts[] = $unsavedFieldCount === 0 ? 'no unsaved changes' : $unsavedFieldCount . ' unsaved ' . ($unsavedFieldCount === 1 ? 'field' : 'fields');
        $invalidFieldCount = count($agentUIState->invalidFieldReferences);
        if ($invalidFieldCount > 0) {
            $noteParts[] = $invalidFieldCount . ' ' . ($invalidFieldCount === 1 ? 'field with a validation message' : 'fields with a validation message');
        }
        if ($agentUIState->openDialogKey !== null) {
            $noteParts[] = 'dialog ' . $agentUIState->openDialogKey . ' open';
        }
        return static::SCREEN_NOTE_LEAD . ' ' . implode(' · ', $noteParts) . '. Values on it can be unsaved; read them with '
            . 'getCurrentScreen, stored values with the data tools. ' . AgentStateDeltaTexts::BACKGROUND_SUFFIX;
    }

    /** Whether the application binds `Agent.UI`: without it no client reports a UI state, and nothing is read */
    protected function isUISkillBound(): bool
    {
        $configuredBinding = Config::get(AISkillsService::CONFIG_SKILL_PACKAGES);
        return is_array($configuredBinding) && isset($configuredBinding[AISkill::AGENT_UI]);
    }

    protected function describeArea(?AgentUIScreen $agentUIScreen): string
    {
        if ($agentUIScreen === null || $agentUIScreen->areaKey === '') {
            return 'an area the application does not describe';
        }
        $areaLabel = trim((string)$agentUIScreen->areaLabel);
        return 'area ' . ($areaLabel !== '' ? LlmTextRendering::shorten(LlmTextRendering::toSingleLine($areaLabel), 80) . ' (' . $agentUIScreen->areaKey . ')' : $agentUIScreen->areaKey);
    }

    protected function getAgentClientAttachmentsService(): AgentClientAttachmentsService
    {
        /** @var AgentClientAttachmentsService $agentClientAttachmentsService */
        $agentClientAttachmentsService = DDDService::instance()->getService(AgentClientAttachmentsService::class);
        return $agentClientAttachmentsService;
    }

    protected function getAgentUIScreenCatalogService(): AgentUIScreenCatalogService
    {
        /** @var AgentUIScreenCatalogService $agentUIScreenCatalogService */
        $agentUIScreenCatalogService = DDDService::instance()->getService(AgentUIScreenCatalogService::class);
        return $agentUIScreenCatalogService;
    }
}
