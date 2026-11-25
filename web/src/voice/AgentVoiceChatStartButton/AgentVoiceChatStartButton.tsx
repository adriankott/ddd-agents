import { startAgentVoiceCall, useIsAgentVoiceCallActive } from "../call/agent-voice-call-store";
import { VoiceBarsIcon } from "../../chat/ConversationMessage/ConversationCallMarkerChip/VoiceBarsIcon/VoiceBarsIcon";
import { useAgentVoiceTranslateText } from "../AgentVoiceCallProvider/agent-voice-call-context";
import styles from "./AgentVoiceChatStartButton.module.scss";

export interface AgentVoiceChatStartButtonProps {
  /** The chat's agent space, where the application has one. */
  agentSpaceId?: number;
  /** The chat's conversation the voice chat continues; without one the backend creates a conversation for it. */
  conversationId?: number;
  /** The owner may not start a voice chat here (the application's own gate). */
  disabled?: boolean;
  testId?: string;
}

/**
 * In a chat input's send spot while nothing is typed and no voice chat runs: starts a voice chat with the agent. An
 * input outside the chat uses it too; the application shows the call's conversation when the provider reports it
 * (`onConversationOfCallChanged`). Disabled while a voice chat is connecting or live.
 */
export const AgentVoiceChatStartButton = (props: AgentVoiceChatStartButtonProps) => {
  const { agentSpaceId, conversationId, disabled = false, testId = "agent-voice-chat-start" } = props;
  const translateText = useAgentVoiceTranslateText();
  const isCallActive = useIsAgentVoiceCallActive();
  const label = translateText("Talk to %agentName%");

  return (
    <button
      type="button"
      className={styles.startButton}
      onClick={() => void startAgentVoiceCall({ agentSpaceId, conversationId })}
      disabled={disabled || isCallActive}
      aria-label={label}
      title={label}
      data-testid={testId}
    >
      <VoiceBarsIcon size={18} />
    </button>
  );
};
