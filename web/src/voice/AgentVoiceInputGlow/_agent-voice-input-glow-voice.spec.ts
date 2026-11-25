import { describe, expect, it } from "vitest";

import type { AgentVoiceCallTurn } from "../call/agent-voice-call-turn";
import { glowVoiceOfAgentVoiceCallTurn } from "./agent-voice-input-glow-voice";

describe("glowVoiceOfAgentVoiceCallTurn", () => {
  it("draws every turn of a call", () => {
    const glowVoiceByTurn: Record<AgentVoiceCallTurn, string> = {
      agent: "agent",
      owner: "owner",
      thinking: "thinking",
      connecting: "thinking",
      listening: "quiet",
      muted: "quiet",
      silentMicrophone: "quiet",
    };
    for (const [turn, glowVoice] of Object.entries(glowVoiceByTurn)) {
      expect(glowVoiceOfAgentVoiceCallTurn(turn as AgentVoiceCallTurn), turn).toBe(glowVoice);
    }
  });
});
