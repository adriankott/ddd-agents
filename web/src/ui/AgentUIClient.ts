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

  constructor(adapters: ScreenAdapters, uiStateSource: AgentUIStateSource | null = null, clientId: string = createAgentUIClientId()) {
    this.clientId = clientId;
    this.runner = new ClientToolRequestRunner(adapters);
    this.uiStateSource = uiStateSource;
  }

  readUIState(): AgentUIState | null {
    try {
      return this.uiStateSource?.readUIState() ?? null;
    } catch {
      return null;
    }
  }

  acceptClientToolRequest(_conversationId: number, delivery: ClientToolRequestDelivery, sendAnswer: ClientToolAnswerSender): void {
    this.runner.accept(delivery, sendAnswer);
  }

  /** Resolves when every accepted request was answered (tests). */
  whenIdle(): Promise<void> {
    return this.runner.whenIdle();
  }
}
