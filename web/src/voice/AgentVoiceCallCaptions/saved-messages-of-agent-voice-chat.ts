import type { ChatMessage } from "../../chat/chat-message";
import type { SavedAgentVoiceCallMessage } from "../call/build-unsaved-agent-voice-call-lines";

/** What the rules below read of a chat message. */
type ChatMessageOfVoiceChat = Pick<ChatMessage, "role" | "message" | "indexInConversation">;

/** The highest index in the conversation among `messages`; -1 for none, or when no message carries an index yet. */
export const highestIndexInConversationOf = (messages: readonly ChatMessageOfVoiceChat[]): number =>
  messages.reduce((highestIndex, message) => Math.max(highestIndex, message.indexInConversation ?? -1), -1);

/**
 * What the chat holds of the voice chat: the agent's and the owner's text messages saved after the voice chat started
 * (an index above `highestIndexBeforeVoiceChat`), in the conversation's order, each as the speaker and the text.
 */
export const savedMessagesOfTheVoiceChat = (
  messages: readonly ChatMessageOfVoiceChat[],
  highestIndexBeforeVoiceChat: number,
): SavedAgentVoiceCallMessage[] =>
  [...messages]
    .filter(
      (message) =>
        (message.role === "ASSISTANT" || message.role === "USER") &&
        typeof message.message === "string" &&
        (message.indexInConversation ?? -1) > highestIndexBeforeVoiceChat,
    )
    .sort((first, second) => (first.indexInConversation ?? 0) - (second.indexInConversation ?? 0))
    .map((message) => ({ speaker: message.role === "ASSISTANT" ? "agent" : "owner", text: message.message as string }));
