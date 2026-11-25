/**
 * What the client reports about its UI with every message and every run-state read (`Agent.UI`): the server keeps
 * only tokens of one pattern (route path, tab, view, references, dialog key) and writes the `[SIGNAL:ui]` note from
 * them and its own screen catalog. Never field values or labels: those reach the model only through `getCurrentScreen`.
 * The wire shape of `AgentUIState` of ddd-agents.
 */
export interface AgentUIState {
  /** The path of the route, e.g. `/orders/42` */
  routePath: string | null;
  /** The route's parameters, in order */
  routeParameters?: { name: string; value: string }[] | null;
  tab?: string | null;
  view?: string | null;
  /** The references of the fields with unsaved changes */
  unsavedFieldReferences?: string[];
  /** The references of the fields with a validation message */
  invalidFieldReferences?: string[];
  /** The key of a dialog or drawer open on the screen */
  openDialogKey?: string | null;
  /** The mode the agent window shows (`full`, `compact`, `closed`, … the application's modes); `AgentUIClient` fills it from the window adapter */
  agentWindow?: string | null;
  /** The size of the browser window in CSS pixels; `AgentUIClient` fills it */
  viewportWidth?: number | null;
  viewportHeight?: number | null;
  visible?: boolean | null;
  /** ISO time of the capture */
  capturedAt?: string | null;
}

/** Reads the UI state now; the application's adapter (ddd-admin-ui ships one for screens built from its blocks). */
export interface AgentUIStateSource {
  readUIState(): AgentUIState | null;
}

/** A client id: 8 to 64 letters, digits and `-`, random per mount of the agent window (the server's pattern). */
export const createAgentUIClientId = (): string => {
  const randomPart =
    typeof crypto !== "undefined" && typeof crypto.randomUUID === "function"
      ? crypto.randomUUID()
      : `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}`;
  return `ui-${randomPart}`.slice(0, 64);
};
