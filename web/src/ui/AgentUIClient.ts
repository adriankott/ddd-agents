import type { AgentUIState, AgentUIStateSource } from "./agent-ui-state";
import { createAgentUIClientId } from "./agent-ui-state";
import { type ClientToolAnswerSender, ClientToolRequestRunner } from "./ClientToolRequestRunner";
import type { ClientToolRequestDelivery, ScreenAdapters } from "./client-tools";

/**
 * What a conversation client binding needs from the UI side of `Agent.UI` (`AgentsHttpConversationClientOptions.uiClient`):
 * the client id and UI state it sends with every message and run-state read, and where a delivered client tool
 * request goes. The binding posts the answer.
 */
export interface AgentUIClientBinding {
  readonly clientId: string;
  readUIState(): AgentUIState | null;
  acceptClientToolRequest(conversationId: number, delivery: ClientToolRequestDelivery, sendAnswer: ClientToolAnswerSender): void;
}

/**
 * The UI side of `Agent.UI` in one object: a client id per mount, the UI state source, and the runner that executes
 * the delivered requests through the application's four adapters. Framework-free; the React hook
 * (`useAgentUIClient`) creates one per mount.
 */
export class AgentUIClient implements AgentUIClientBinding {
  readonly clientId: string;

  protected readonly runner: ClientToolRequestRunner;

  protected readonly uiStateSource: AgentUIStateSource | null;

  protected readonly adapters: ScreenAdapters;

  protected acceptedClientToolRequestCount = 0;

  constructor(adapters: ScreenAdapters, uiStateSource: AgentUIStateSource | null = null, clientId: string = createAgentUIClientId()) {
    this.clientId = clientId;
    this.adapters = adapters;
    this.runner = new ClientToolRequestRunner(adapters);
    this.uiStateSource = uiStateSource;
  }

  /**
   * The source's UI state with the agent window's mode (the window adapter) and the browser window's size, unless the
   * source reports them itself: the agent learns whether its window covers the page and whether a panel fits beside it.
   */
  readUIState(): AgentUIState | null {
    try {
      const uiState = this.uiStateSource?.readUIState() ?? null;
      if (uiState === null) return null;
      const completedUIState: AgentUIState = { ...uiState };
      const agentWindowMode = uiState.agentWindow ?? this.adapters.agentWindow?.getMode?.() ?? null;
      if (agentWindowMode !== null) completedUIState.agentWindow = agentWindowMode;
      if (typeof window !== 'undefined') {
        completedUIState.viewportWidth = uiState.viewportWidth ?? Math.round(window.innerWidth);
        completedUIState.viewportHeight = uiState.viewportHeight ?? Math.round(window.innerHeight);
      }
      return completedUIState;
    } catch {
      return null;
    }
  }

  acceptClientToolRequest(_conversationId: number, delivery: ClientToolRequestDelivery, sendAnswer: ClientToolAnswerSender): void {
    this.acceptedClientToolRequestCount += 1;
    this.runner.accept(delivery, sendAnswer);
  }

  /** How many client tool requests were delivered to this client so far (a repeat delivery counts again). */
  countAcceptedClientToolRequests(): number {
    return this.acceptedClientToolRequestCount;
  }

  /** Resolves when every accepted request was answered (tests). */
  whenIdle(): Promise<void> {
    return this.runner.whenIdle();
  }
}
