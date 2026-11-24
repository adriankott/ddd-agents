/**
 * `@mgamadeus/ddd-agents/ui` — the client side of `Agent.UI`: the runner of the client tool requests, the four
 * adapter contracts an application implements (`ScreenTargetSource`, `ScreenNavigator`, `ScreenReader`,
 * `ScreenHighlighter`), the Markdown writer and reference registry, DOM defaults for reader and highlighter, the UI
 * state the client reports, and the hook.
 */
export * from "../ui/client-tools";
export * from "../ui/agent-ui-state";
export { ClientToolRequestRunner, MAX_SCREEN_CHARS, type ClientToolAnswerSender } from "../ui/ClientToolRequestRunner";
export { AgentUIClient, type AgentUIClientBinding } from "../ui/AgentUIClient";
export { useAgentUIClient } from "../ui/useAgentUIClient";
export * from "../ui/screen-markdown";
export * from "../ui/DomScreenReader";
export * from "../ui/DomScreenHighlighter";
