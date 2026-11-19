import { Text } from "../../provider/components";
import { AgentChatActivityDots, type AgentChatActivityDotsVariant } from "../AgentChatActivityDots/AgentChatActivityDots";
import type { AgentChatActivityProps } from "../controller/AgentChatController";
import styles from "./AgentChatActivityIndicator.module.scss";

export interface AgentChatActivityIndicatorProps extends AgentChatActivityProps {
  /** How the dots move: `bounce` (the default) or the calm `pulse`. */
  dotsVariant?: AgentChatActivityDotsVariant;
}

/**
 * The package default of the agent's activity at the tail of the thread (`AgentChat`'s `ThinkingIndicator`): three
 * dots and the line the activity ticker shows right now — the step the agent works on — while a turn runs; nothing
 * between turns or after a failed one (the error card speaks then). An app with an agent avatar replaces it
 * (host application: the agent's face beside the same ticker lines). `AgentChatActivityStack` is the stacked feed.
 */
export const AgentChatActivityIndicator = (props: AgentChatActivityIndicatorProps) => {
  const { active, failed, activityTicker, dotsVariant = "bounce" } = props;
  const isWorking = !failed && (active || activityTicker.phase === "live");
  if (!isWorking) return null;
  const currentLines = activityTicker.currentTurn?.lines ?? [];
  const currentLine = currentLines.length > 0 ? currentLines[currentLines.length - 1].line : null;
  return (
    <div className={styles.activity} role="status" aria-live="polite" data-testid="agent-chat-activity">
      <AgentChatActivityDots variant={dotsVariant} />
      {currentLine !== null ? (
        <Text role="help" color="--agents-fg-light" translate={false}>
          {currentLine}
        </Text>
      ) : (
        <Text role="help" color="--agents-fg-light">
          Working on it…
        </Text>
      )}
    </div>
  );
};
