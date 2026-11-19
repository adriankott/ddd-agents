import { classNames } from "../../provider/class-names";
import styles from "./AgentChatActivityDots.module.scss";

/** How the three dots move: `bounce` hops them in turn (the indicator's default); `pulse` only fades and grows them, calmly. */
export type AgentChatActivityDotsVariant = "bounce" | "pulse";

export interface AgentChatActivityDotsProps {
  /** Default `bounce`. */
  variant?: AgentChatActivityDotsVariant;
}

/**
 * The three working dots of the activity components, decorative (`aria-hidden`). Coloured by `--agents-fg-light`;
 * still under `prefers-reduced-motion`.
 */
export const AgentChatActivityDots = ({ variant = "bounce" }: AgentChatActivityDotsProps) => (
  <div
    className={classNames(styles.dots, variant === "pulse" ? styles.pulse : styles.bounce)}
    aria-hidden="true"
    data-agents-activity-dots={variant}
  >
    <span />
    <span />
    <span />
  </div>
);
