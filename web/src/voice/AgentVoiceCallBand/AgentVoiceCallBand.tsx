import { getAgentDisplayName } from "../../provider/environment";
import type { ReactNode } from "react";
import {
  type AgentVoiceCallSnapshot,
  dismissAgentVoiceCallOutcome,
  hangUpAgentVoiceCall,
  IDLE_AGENT_VOICE_CALL,
  isAgentVoiceCallActive,
  isAgentVoiceCallShownInAgentSpace,
  startAgentVoiceCall,
  toggleAgentVoiceCallMute,
  useAgentVoiceCallSnapshot,
} from "../call/agent-voice-call-store";
import { type AgentVoiceCallTurn, turnOfAgentVoiceCall } from "../call/agent-voice-call-turn";
import { formatAgentVoiceCallDuration } from "../call/format-agent-voice-call-duration";
import { useAgentVoiceCallElapsedSeconds } from "../call/use-agent-voice-call-elapsed-seconds";
import { type AgentVoiceCallerLabel, buildAgentVoiceCallerLabel } from "../ring/agent-voice-caller-label";
import {
  acceptAgentVoiceIncomingRing,
  declineAgentVoiceIncomingRing,
  useAgentVoiceIncomingRing,
  useAgentVoiceRingBandHost,
} from "../ring/agent-voice-incoming-ring-store";
import type { AgentVoiceRing } from "../ring/agent-voice-ring";
import { AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS, type AgentVoiceAnswerRefusal } from "../ring/answer-incoming-agent-voice-call";
import { textKey } from "../../provider/text-key";
import { VoiceBarsIcon } from "../../chat/ConversationMessage/ConversationCallMarkerChip/VoiceBarsIcon/VoiceBarsIcon";
import { AgentVoiceCloseIcon, AgentVoiceHangUpIcon, AgentVoiceMicrophoneIcon } from "../AgentVoiceIcons/AgentVoiceIcons";
import { useAgentVoiceTranslateText } from "../AgentVoiceCallProvider/agent-voice-call-context";
import { agentVoicePartTestId } from "../agent-voice-part-test-id";
import { agentVoiceCallOutcomeTitleTextKey } from "./agent-voice-call-outcome-title-text-key";
import styles from "./AgentVoiceCallBand.module.scss";

const TURN_STATUS_TEXT_KEYS: Readonly<Record<AgentVoiceCallTurn, string>> = {
  connecting: textKey("Connecting…"),
  muted: textKey("You’re muted"),
  silentMicrophone: textKey("I can’t hear you — is your microphone on?"),
  agent: textKey("%agentName% is speaking"),
  owner: textKey("You’re speaking"),
  thinking: textKey("%agentName% is thinking"),
  listening: textKey("%agentName% is listening"),
};

interface AgentVoiceBandActionProps {
  label: string;
  icon: ReactNode;
  onClick: () => void;
  testId: string;
  /** The label stays out of sight and only names the button (the band's Close). */
  iconOnly?: boolean;
  pressed?: boolean;
  variant?: "end" | "accept";
  disabled?: boolean;
}

/** The band's root test id while the application sets none. */
const DEFAULT_TEST_ID = "agent-voice-call-band";

/** Whether the application gave the band controls of its own: a node that renders something. */
const hasApplicationActions = (actions: ReactNode): boolean => actions !== undefined && actions !== null && actions !== false;

/** The application's own controls (`actions`), first in the band's row of buttons. */
const AgentVoiceBandApplicationActions = (props: { actions: ReactNode; testId: string }) => {
  const { actions, testId } = props;
  if (!hasApplicationActions(actions)) return null;
  return (
    <div className={styles.applicationActions} data-testid={testId}>
      {actions}
    </div>
  );
};

/** A pill on the dark band. */
const AgentVoiceBandAction = (props: AgentVoiceBandActionProps) => {
  const { label, icon, onClick, testId, iconOnly = false, pressed, variant, disabled = false } = props;
  return (
    <button
      type="button"
      className={styles.action}
      onClick={onClick}
      aria-pressed={pressed}
      disabled={disabled}
      data-variant={variant}
      data-icon-only={iconOnly || undefined}
      data-testid={testId}
    >
      {icon}
      <span className={styles.actionLabel}>{label}</span>
    </button>
  );
};

export interface AgentVoiceCallIncomingRing {
  ring?: AgentVoiceRing;
  /** The caller line of `ring`; without one the band words the ring's own label key. */
  callerLabel?: AgentVoiceCallerLabel;
  isAnswering: boolean;
  /** Why the last Accept did not get the call. */
  refusal?: AgentVoiceAnswerRefusal;
  onAccept: () => void;
  /** Decline: lets the ring go. */
  onDecline: () => void;
}

export interface AgentVoiceCallBandViewProps {
  call: AgentVoiceCallSnapshot;
  elapsedSeconds: number;
  /** Talk with the agent again after an ended or failed voice chat. */
  onStartVoiceChat: () => void;
  /** The agent ringing this client: while no voice chat runs, the band asks to talk. */
  incoming?: AgentVoiceCallIncomingRing;
  /** The application's avatar of the agent, before the name (while a call runs or the agent rings). */
  agentSpot?: ReactNode;
  /**
   * The application's own controls (a button that opens the chat in a window), in the band's row of buttons before
   * the band's own. Rendered only while the band shows something (a call, a ring, a refused answer, an outcome).
   * Default: none.
   */
  actions?: ReactNode;
  /**
   * The test id of the band; its inner elements take `${testId}-<part>` (`-status`, `-mute`, `-end`,
   * `-incoming-decline`, `-incoming-accept`, `-incoming-refusal`, `-ended-status`, `-failure`, `-start-again`, `-close`,
   * `-actions`). Default: `agent-voice-call-band`, the inner elements their fixed `agent-voice-call-…` ids.
   */
  testId?: string;
}

/**
 * The voice chat in the band over the chat input: before it, the agent asking to talk (Decline, Accept); during it,
 * who is on the line, for how long and whose turn it is, with Mute and End; after it, the outcome with the way to talk
 * again. Driven only by its props: the band feeds it the live call, a test or a showcase feeds it fixtures.
 */
export const AgentVoiceCallBandView = (props: AgentVoiceCallBandViewProps) => {
  const { call, elapsedSeconds, onStartVoiceChat, incoming, agentSpot, actions, testId } = props;
  const translateText = useAgentVoiceTranslateText();
  const duration = formatAgentVoiceCallDuration(elapsedSeconds);
  const agentName = getAgentDisplayName();
  const bandTestId = testId ?? DEFAULT_TEST_ID;
  const applicationActions = (
    <AgentVoiceBandApplicationActions
      actions={actions}
      testId={agentVoicePartTestId(testId, "actions", "agent-voice-call-band-actions")}
    />
  );
  const spot = agentSpot === undefined || agentSpot === null ? null : <div className={styles.spot}>{agentSpot}</div>;

  if (isAgentVoiceCallActive(call.phase)) {
    return (
      <div className={styles.band} data-testid={bandTestId} data-phase={call.phase}>
        {/* Keyed by the phase: the words and buttons come in again as the call connects. */}
        <div key={call.phase} className={styles.content}>
          {spot}
          <div className={styles.who} aria-live="polite">
            <span className={styles.title}>{agentName}</span>
            {call.phase === "live" && <span className={styles.time}>{duration}</span>}
            <span className={styles.status} data-testid={agentVoicePartTestId(testId, "status", "agent-voice-call-status")}>
              {translateText(TURN_STATUS_TEXT_KEYS[turnOfAgentVoiceCall(call)])}
            </span>
          </div>
          <div className={styles.actions}>
            {applicationActions}
            <AgentVoiceBandAction
              label={call.isMuted ? translateText("Unmute") : translateText("Mute")}
              icon={<AgentVoiceMicrophoneIcon />}
              onClick={toggleAgentVoiceCallMute}
              pressed={call.isMuted}
              disabled={call.phase !== "live"}
              testId={agentVoicePartTestId(testId, "mute", "agent-voice-call-mute")}
            />
            <AgentVoiceBandAction
              label={translateText("End")}
              icon={<AgentVoiceHangUpIcon />}
              onClick={() => hangUpAgentVoiceCall()}
              variant="end"
              testId={agentVoicePartTestId(testId, "end", "agent-voice-call-end")}
            />
          </div>
        </div>
      </div>
    );
  }

  if (incoming?.ring) {
    const callerLabel = incoming.callerLabel ?? buildAgentVoiceCallerLabel(incoming.ring, translateText, agentName);
    return (
      <div className={styles.band} data-testid={bandTestId} data-phase="incoming">
        <div key="incoming" className={styles.content}>
          {spot}
          <div className={styles.who} role="alert">
            <span className={styles.title}>{callerLabel.text}</span>
            {callerLabel.detail ? <span className={styles.status}>{callerLabel.detail}</span> : null}
          </div>
          <div className={styles.actions}>
            {applicationActions}
            <AgentVoiceBandAction
              label={translateText("Decline")}
              icon={<AgentVoiceCloseIcon />}
              onClick={incoming.onDecline}
              variant="end"
              disabled={incoming.isAnswering}
              testId={agentVoicePartTestId(testId, "incoming-decline", "agent-voice-call-incoming-decline")}
            />
            <AgentVoiceBandAction
              label={translateText("Accept")}
              icon={<VoiceBarsIcon size={16} />}
              onClick={incoming.onAccept}
              variant="accept"
              disabled={incoming.isAnswering}
              testId={agentVoicePartTestId(testId, "incoming-accept", "agent-voice-call-incoming-accept")}
            />
          </div>
        </div>
      </div>
    );
  }

  if (incoming?.refusal && call.phase === "idle") {
    return (
      <div className={styles.band} data-testid={bandTestId} data-phase="refused">
        <div key="refused" className={styles.content}>
          <div className={styles.who} role="status">
            <span
              className={styles.status}
              data-testid={agentVoicePartTestId(testId, "incoming-refusal", "agent-voice-call-incoming-refusal")}
            >
              {translateText(AGENT_VOICE_ANSWER_REFUSAL_TEXT_KEYS[incoming.refusal])}
            </span>
          </div>
          {hasApplicationActions(actions) ? <div className={styles.actions}>{applicationActions}</div> : null}
        </div>
      </div>
    );
  }

  if (call.phase !== "ended" && call.phase !== "failed") return null;
  // Hung up before `session.started`: nothing was said.
  const wasConnected = call.connectedAtMs !== undefined;
  const titleTextKey = agentVoiceCallOutcomeTitleTextKey(call);

  return (
    <div className={styles.band} data-testid={bandTestId} data-phase={call.phase} data-end-reason={call.endReason}>
      <div key={call.phase} className={styles.content}>
        <div className={styles.who} aria-live="polite">
          <span
            className={styles.title}
            data-testid={
              call.phase === "ended" && wasConnected
                ? agentVoicePartTestId(testId, "ended-status", "agent-voice-call-ended-status")
                : undefined
            }
          >
            {translateText(titleTextKey)}
          </span>
          {call.phase === "ended" && wasConnected && <span className={styles.time}>{duration}</span>}
          {call.phase === "failed" && call.failureTextKey && (
            <span className={styles.status} data-testid={agentVoicePartTestId(testId, "failure", "agent-voice-call-failure")}>
              {translateText(call.failureTextKey)}
            </span>
          )}
        </div>
        <div className={styles.actions}>
          {applicationActions}
          <AgentVoiceBandAction
            label={call.phase === "failed" ? translateText("Try again") : translateText("Talk with %agentName% again")}
            icon={<VoiceBarsIcon size={16} />}
            onClick={onStartVoiceChat}
            variant="accept"
            testId={agentVoicePartTestId(testId, "start-again", "agent-voice-call-start-again")}
          />
          <AgentVoiceBandAction
            label={translateText("Close")}
            icon={<AgentVoiceCloseIcon />}
            onClick={dismissAgentVoiceCallOutcome}
            iconOnly
            testId={agentVoicePartTestId(testId, "close", "agent-voice-call-band-close")}
          />
        </div>
      </div>
    </div>
  );
};

export interface AgentVoiceCallBandProps {
  /** The application's avatar of the agent for the call (it gets the call to show whose turn it is). */
  renderAgentSpot?: (call: AgentVoiceCallSnapshot) => ReactNode;
  /** The chat's agent space, where the application has one: a voice chat running in another one shows nothing here. */
  agentSpaceId?: number;
  /**
   * False while the chat that holds the band is out of sight (behind another surface): the band then hosts no ring,
   * and the incoming call shows its own card. Default true.
   */
  isPresented?: boolean;
  /** The application's own controls in the band (see `AgentVoiceCallBandViewProps.actions`). Default: none. */
  actions?: ReactNode;
  /** The test id of the band and the prefix of its inner ids (see `AgentVoiceCallBandViewProps.testId`). */
  testId?: string;
}

/**
 * The band of the agent ringing this client and of the running (or just finished) voice chat; renders nothing while
 * there is none. While presented it is where the ring is answered, so the page-level incoming call stays away. "Talk
 * again" starts a voice chat in the ended call's agent space and conversation.
 */
export const AgentVoiceCallBand = (props: AgentVoiceCallBandProps) => {
  const { renderAgentSpot, agentSpaceId, isPresented = true, actions, testId } = props;
  const liveCall = useAgentVoiceCallSnapshot();
  const call = isAgentVoiceCallShownInAgentSpace(liveCall, agentSpaceId) ? liveCall : IDLE_AGENT_VOICE_CALL;
  const elapsedSeconds = useAgentVoiceCallElapsedSeconds(call);
  const { ring, callerLabel, isAnswering, refusal } = useAgentVoiceIncomingRing();
  useAgentVoiceRingBandHost(isPresented);

  return (
    <AgentVoiceCallBandView
      call={call}
      elapsedSeconds={elapsedSeconds}
      agentSpot={renderAgentSpot?.(call)}
      actions={actions}
      testId={testId}
      onStartVoiceChat={() =>
        void startAgentVoiceCall({ agentSpaceId: call.agentSpaceId ?? agentSpaceId, conversationId: call.conversationId })
      }
      incoming={{
        ring,
        callerLabel,
        isAnswering,
        refusal,
        onAccept: () => void acceptAgentVoiceIncomingRing(),
        onDecline: declineAgentVoiceIncomingRing,
      }}
    />
  );
};
