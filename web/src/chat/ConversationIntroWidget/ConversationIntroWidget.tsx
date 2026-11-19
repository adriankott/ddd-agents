import { Text } from "../../provider/components";
import type { ChatAssistantIdentity, ChatAssistantOpening } from "../chat-assistant-identity";
import React from "react";
import { useAgentsEnvironment } from "../../provider/AgentsProvider";
import styles from "../Conversation.module.scss";
import { ConversationSuggestionWidget } from "../ConversationSuggestionWidget/ConversationSuggestionWidget";

interface ConversationIntroWidgetProps {
  aiPromptExtension: ChatAssistantIdentity;
  /** The icon class of the extension, an icon of the app's icon set. */
  aiPromptExtensionIcon: string;
  suggestions?: ChatAssistantOpening[];
  onSuggestionClick: (text: string) => void;
}

export const ConversationIntroWidget = ({
  aiPromptExtension,
  aiPromptExtensionIcon,
  suggestions,
  onSuggestionClick,
}: ConversationIntroWidgetProps) => {
  const { AssistantIconBadge } = useAgentsEnvironment();
  return (
    <div className={styles.extensionIntro}>
      {AssistantIconBadge ? (
        <AssistantIconBadge
          iconName={aiPromptExtensionIcon}
          color="var(--agents-fg-on-accent)"
          backgroundColor="var(--agents-assistant-400)"
          isLarge
        />
      ) : null}
      <Text
        role="intro"
        weight="bold"
        textAlign={"center"}
        className={styles.extensionIntroTitle}
      >
        {aiPromptExtension.name}
      </Text>
      <Text
        role="body"
        textAlign={"center"}
        color="var(--agents-fg-light)"
        className={styles.extensionIntroDescription}
      >
        {aiPromptExtension.description}
      </Text>
      <div className={styles.suggestions}>
        {suggestions?.map((suggestion, index) => (
          <ConversationSuggestionWidget
            key={"suggestion_prop_" + index}
            suggestion={suggestion}
            onClick={() => onSuggestionClick(suggestion.openingText!)}
          />
        ))}
      </div>
    </div>
  );
};
