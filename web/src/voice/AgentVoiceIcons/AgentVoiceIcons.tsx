import type { ReactNode } from "react";
import styles from "./AgentVoiceIcons.module.scss";

// The line icons of the band and the incoming call. The mark of a voice chat itself (five sound bars) is the chat's
// own `VoiceBarsIcon`, the one its call markers show.

export interface AgentVoiceLineIconProps {
  /** Edge length in px; the icon takes the current text colour. */
  size?: number;
}

const renderLineIcon = (size: number, paths: ReactNode) => (
  <svg className={styles.icon} width={size} height={size} viewBox="0 0 24 24" aria-hidden focusable="false">
    {paths}
  </svg>
);

/** The microphone: the band's Mute and the incoming call's Accept. */
export const AgentVoiceMicrophoneIcon = (props: AgentVoiceLineIconProps) =>
  renderLineIcon(
    props.size ?? 16,
    <path d="M12 3.5a3 3 0 0 0-3 3v5a3 3 0 0 0 6 0v-5a3 3 0 0 0-3-3ZM5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v2.5" />,
  );

/** The receiver laid down: the band's End. */
export const AgentVoiceHangUpIcon = (props: AgentVoiceLineIconProps) =>
  renderLineIcon(
    props.size ?? 16,
    <path d="M3.5 14.5c4.7-4.7 12.3-4.7 17 0l-2.2 2.2-3.1-1.4v-2.4a9.6 9.6 0 0 0-6.4 0v2.4l-3.1 1.4-2.2-2.2Z" />,
  );

/** The cross: Decline and the band's Close. */
export const AgentVoiceCloseIcon = (props: AgentVoiceLineIconProps) =>
  renderLineIcon(props.size ?? 16, <path d="M6 6l12 12M18 6 6 18" />);
