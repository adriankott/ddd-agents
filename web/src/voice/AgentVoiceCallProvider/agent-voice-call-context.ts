import { createContext, useCallback, useContext } from "react";
import { useAgentsTexts } from "../../provider/AgentsProvider";
import { type AgentsTexts, getAgentDisplayName } from "../../provider/environment";
import type { AgentVoiceRingSource } from "../ring/agent-voice-ring-source";

/** What `AgentVoiceCallProvider` carries to the voice components below it. */
export interface AgentVoiceCallContextValue {
  ringSource: AgentVoiceRingSource | undefined;
}

/** Without a provider: no ring source (nothing rings). */
export const AgentVoiceCallContext = createContext<AgentVoiceCallContextValue>({ ringSource: undefined });

/** The ring source of the provider above (undefined: the application has no ring transport). */
export const useAgentVoiceRingSource = (): AgentVoiceRingSource | undefined => useContext(AgentVoiceCallContext).ringSource;

/** The text function of the voice components: a text key and what its `%placeholders%` are filled with. */
export type AgentVoiceTranslateText = (key: string, replacements?: Record<string, string | number>) => string;

/**
 * The text function of the voice components: the texts of the environment around (`AgentsEnvironment.texts`, a
 * subtree's own over the app-wide ones) with `%agentName%` filled with the name the owner knows the agent by
 * (`AgentsEnvironment.agentDisplayName`).
 */
export const useAgentVoiceTranslateText = (): AgentVoiceTranslateText => {
  const texts: AgentsTexts = useAgentsTexts();
  return useCallback<AgentVoiceTranslateText>(
    (key, replacements) => texts.translate(key, { agentName: getAgentDisplayName(), ...replacements }),
    [texts],
  );
};
