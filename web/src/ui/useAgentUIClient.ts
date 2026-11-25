import { useRef } from "react";
import type { AgentUIStateSource } from "./agent-ui-state";
import { AgentUIClient } from "./AgentUIClient";
import type { ScreenAdapters } from "./client-tools";

/**
 * One `AgentUIClient` per mount of the agent window: a stable client id and the runner of the delivered requests. The
 * adapters are read through the latest render (a ref), so an application may pass a new object on every render. Hand
 * the result to the conversation client binding (`createAgentsHttpConversationClient({ uiClient })`).
 */
export const useAgentUIClient = (adapters: ScreenAdapters, uiStateSource: AgentUIStateSource | null = null): AgentUIClient => {
  const adaptersRef = useRef(adapters);
  adaptersRef.current = adapters;
  const uiStateSourceRef = useRef(uiStateSource);
  uiStateSourceRef.current = uiStateSource;
  const clientRef = useRef<AgentUIClient | null>(null);
  if (clientRef.current === null) {
    const latestAdapters: ScreenAdapters = {
      targets: { listTargets: () => adaptersRef.current.targets.listTargets() },
      navigator: {
        describeBlockers: () => adaptersRef.current.navigator.describeBlockers(),
        open: (target, request) => adaptersRef.current.navigator.open(target, request),
        currentLocation: () => adaptersRef.current.navigator.currentLocation(),
      },
      reader: { read: options => adaptersRef.current.reader.read(options) },
      highlighter: {
        mark: (references, note) => adaptersRef.current.highlighter.mark(references, note),
        clear: () => adaptersRef.current.highlighter.clear(),
      },
      isVisible: () => adaptersRef.current.isVisible(),
    };
    // the optional adapters: present on the object when the latest render has them
    Object.defineProperties(latestAdapters, {
      picture: { enumerable: true, get: () => adaptersRef.current.picture },
      agentWindow: { enumerable: true, get: () => adaptersRef.current.agentWindow },
      pathGuide: { enumerable: true, get: () => adaptersRef.current.pathGuide },
      requestHandlers: { enumerable: true, get: () => adaptersRef.current.requestHandlers },
    });
    clientRef.current = new AgentUIClient(latestAdapters, { readUIState: () => uiStateSourceRef.current?.readUIState() ?? null });
  }
  return clientRef.current;
};
