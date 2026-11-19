import { BACKLOG_LINE_PACE_MS, LINE_PACE_MS } from "./activity-ticker";
import { SUCCESS_BEAT_MS } from "./use-activity-ticker";
import { ACTIVITY_REQUEST_WINDOW_MS, RUNNING_FLOOR_MS } from "./use-conversation-activity";

/**
 * The timing of the chat's activity lines and of its working indicator, for a host whose delivery differs from the
 * package's polling default. Every field is optional and defaults to the package's value; a value that is no finite
 * number at or above 0 counts as unset.
 *
 * A host with a realtime push that writes `activityUpdatedAt` when the narrator finishes lowers
 * `activityRequestWindowMs` (and the paces), so a narrated line shows as soon as the push arrives instead of after the
 * next 3 s window.
 */
export interface AgentChatActivityTiming {
  /** At most one activity read per this many milliseconds per conversation. Default 3000 (`ACTIVITY_REQUEST_WINDOW_MS`). */
  activityRequestWindowMs?: number;
  /** While a turn runs, an activity read also fires on this cadence (the floor for a dead signal). Default 4000 (`RUNNING_FLOOR_MS`). */
  runningFloorMs?: number;
  /** The least time a line stands beside the working face before the next one replaces it. Default 2200 (`LINE_PACE_MS`). */
  linePaceMs?: number;
  /** The shorter wait while more than two lines are queued. Default 1400 (`BACKLOG_LINE_PACE_MS`). */
  backlogLinePaceMs?: number;
  /** How long the face's success beat rests after the turn before it turns idle. Default 1400 (`SUCCESS_BEAT_MS`). */
  successBeatMs?: number;
}

/** Every timing value set: the resolved form the chat's hooks read. */
export type ResolvedAgentChatActivityTiming = Required<AgentChatActivityTiming>;

/** The package's timing: what a chat uses when neither the chat nor the environment names a value. */
export const DEFAULT_AGENT_CHAT_ACTIVITY_TIMING: ResolvedAgentChatActivityTiming = {
  activityRequestWindowMs: ACTIVITY_REQUEST_WINDOW_MS,
  runningFloorMs: RUNNING_FLOOR_MS,
  linePaceMs: LINE_PACE_MS,
  backlogLinePaceMs: BACKLOG_LINE_PACE_MS,
  successBeatMs: SUCCESS_BEAT_MS,
};

const isUsableMilliseconds = (value: unknown): value is number =>
  typeof value === "number" && Number.isFinite(value) && value >= 0;

/**
 * The timing of one chat: each field from the first source that sets a usable value — the chat's own
 * (`AgentChat activityTiming`), then the environment's (`AgentsEnvironment.chatActivityTiming`), then the package's.
 */
export const resolveAgentChatActivityTiming = (
  ...sources: (AgentChatActivityTiming | null | undefined)[]
): ResolvedAgentChatActivityTiming => {
  const resolved = { ...DEFAULT_AGENT_CHAT_ACTIVITY_TIMING };
  (Object.keys(resolved) as (keyof ResolvedAgentChatActivityTiming)[]).forEach((key) => {
    const source = sources.find((candidate) => isUsableMilliseconds(candidate?.[key]));
    if (source) resolved[key] = source[key] as number;
  });
  return resolved;
};
