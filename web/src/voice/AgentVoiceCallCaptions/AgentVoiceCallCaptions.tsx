import { useEffect, useMemo, useState } from "react";
import type { ChatMessage } from "../../chat/chat-message";
import {
  ConversationMessageUser,
  type ConversationMessageUserTheme,
} from "../../chat/ConversationMessage/ConversationMessageUser/ConversationMessageUser";
import { ConversationMessageWidgets } from "../../chat/ConversationMessage/ConversationMessageWidgets/ConversationMessageWidgets";
import { type AgentsChatState, useAgentsChatSelector, useOptionalAgentsChatStore } from "../../chat/store";
import {
  type AgentVoiceCallSnapshot,
  isAgentVoiceCallActive,
  isAgentVoiceCallShownInAgentSpace,
  useAgentVoiceCallSnapshot,
} from "../call/agent-voice-call-store";
import { buildUnsavedAgentVoiceCallLines } from "../call/build-unsaved-agent-voice-call-lines";
import { agentVoicePartTestId } from "../agent-voice-part-test-id";
import { highestIndexInConversationOf, savedMessagesOfTheVoiceChat } from "./saved-messages-of-agent-voice-chat";
import styles from "./AgentVoiceCallCaptions.module.scss";

const NO_MESSAGES: ChatMessage[] = [];
const selectMessages = (state: AgentsChatState) => state.messages;

interface AgentVoiceCallCaptionLinesProps {
  call: AgentVoiceCallSnapshot;
  ownerMessageTheme: ConversationMessageUserTheme | undefined;
  testId: string | undefined;
}

/** The lines of one voice chat: mounted while it is connecting or live, so every voice chat starts with its own count. */
const AgentVoiceCallCaptionLines = (props: AgentVoiceCallCaptionLinesProps) => {
  const { call, ownerMessageTheme, testId } = props;
  const chatStore = useOptionalAgentsChatStore();
  const messages = useAgentsChatSelector(chatStore, selectMessages, null) ?? NO_MESSAGES;
  const [highestIndexBeforeVoiceChat, setHighestIndexBeforeVoiceChat] = useState<number | undefined>(undefined);

  // Taken once as the voice chat starts: what the chat held before was not said in it.
  useEffect(() => {
    if (highestIndexBeforeVoiceChat === undefined) setHighestIndexBeforeVoiceChat(highestIndexInConversationOf(messages));
  }, [highestIndexBeforeVoiceChat, messages]);

  const unsavedLines = useMemo(
    () =>
      highestIndexBeforeVoiceChat === undefined
        ? []
        : buildUnsavedAgentVoiceCallLines(call.lines, savedMessagesOfTheVoiceChat(messages, highestIndexBeforeVoiceChat)),
    [call.lines, messages, highestIndexBeforeVoiceChat],
  );

  return (
    <div className={styles.captions} data-testid={testId ?? "agent-voice-call-captions"}>
      {unsavedLines.map((line) =>
        line.speaker === "agent" ? (
          <div
            key={line.id}
            className={styles.line}
            data-testid={agentVoicePartTestId(testId, "caption-agent", "agent-voice-call-caption-agent")}
          >
            <ConversationMessageWidgets message={line.text} interactive={false} justDelivered={false} hideAssistantMeta />
          </div>
        ) : (
          <div
            key={line.id}
            className={styles.line}
            data-testid={agentVoicePartTestId(testId, "caption-owner", "agent-voice-call-caption-owner")}
          >
            <ConversationMessageUser text={line.text} theme={ownerMessageTheme} />
          </div>
        ),
      )}
    </div>
  );
};

export interface AgentVoiceCallCaptionsProps {
  /** The chat's agent space, where the application has one: a voice chat running in another one shows nothing here. */
  agentSpaceId?: number;
  /** The theme of the owner's line, as the chat renders the owner's messages. Default: the message component's own. */
  ownerMessageTheme?: ConversationMessageUserTheme;
  /**
   * The test id of the captions; the agent's and the owner's lines take `${testId}-caption-agent` and
   * `${testId}-caption-owner`. Default: `agent-voice-call-captions`, the lines `agent-voice-call-caption-agent` and
   * `agent-voice-call-caption-owner`.
   */
  testId?: string;
}

/**
 * The end of the chat while a voice chat runs: what is being said that the chat does not hold yet, streamed from the
 * browser as the agent's prose and the owner's bubble (the chat's own message components), each sentence giving way
 * to the real message once the backend saved it. Sits inside the chat, after its message list (it reads the chat's
 * messages from the chat store in the context); outside one, every line of the call shows. Renders nothing while no
 * voice chat is connecting or live.
 */
export const AgentVoiceCallCaptions = (props: AgentVoiceCallCaptionsProps) => {
  const { agentSpaceId, ownerMessageTheme, testId } = props;
  const call = useAgentVoiceCallSnapshot();
  if (!isAgentVoiceCallActive(call.phase) || !isAgentVoiceCallShownInAgentSpace(call, agentSpaceId)) return null;
  return <AgentVoiceCallCaptionLines call={call} ownerMessageTheme={ownerMessageTheme} testId={testId} />;
};
