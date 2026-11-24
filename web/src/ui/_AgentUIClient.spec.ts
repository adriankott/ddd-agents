import { describe, expect, test, vi } from "vitest";
import { createAgentsHttpConversationClient } from "../client/http/createAgentsHttpConversationClient";
import { AgentUIClient } from "./AgentUIClient";
import type { ScreenAdapters } from "./client-tools";

/**
 * The UI side of `Agent.UI` over the HTTP binding: the client id and UI state ride on the run-state read and on a
 * post, a delivered request runs through the adapters and its answer is posted to the answer route once.
 */
const adapters: ScreenAdapters = {
  targets: { listTargets: () => [] },
  navigator: {
    describeBlockers: () => null,
    open: async () => ({ route: "/orders", title: "Orders" }),
    currentLocation: () => ({ route: "/orders", title: "Orders" }),
  },
  reader: { read: () => ({ markdown: "# Orders", route: "/orders", title: "Orders", references: {} }) },
  highlighter: { mark: () => ({ marked: {}, missing: [], outOfView: [], collapsed: [] }), clear: () => undefined },
  isVisible: () => true,
};

describe("AgentUIClient with the HTTP binding", () => {
  test("the run-state read carries the UI state, the delivered request is answered once", async () => {
    const requests: Array<{ url: URL; method: string; body: unknown }> = [];
    const answers = [
      { body: { id: 7, runState: "RUNNING", clientToolRequest: { id: 31, requestType: "READ_SCREEN", argumentsJson: "{}", secondsLeft: 12 } } },
      { body: { requestId: 31, accepted: true } },
      { body: { id: 7, runState: "RUNNING", clientToolRequest: { id: 31, requestType: "READ_SCREEN", argumentsJson: "{}", secondsLeft: 12 } } },
    ];
    const fetchFunction = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      requests.push({ url: new URL(String(input), "https://host.test"), method: init?.method ?? "GET", body: init?.body ? JSON.parse(String(init.body)) : undefined });
      return new Response(JSON.stringify(answers.shift()?.body ?? {}), { status: 200 });
    }) as unknown as typeof fetch;
    const uiClient = new AgentUIClient(adapters, { readUIState: () => ({ routePath: "/orders", unsavedFieldReferences: ["f.note"] }) }, "ui-client-0001");
    const client = createAgentsHttpConversationClient({ baseUrl: "/api/agents/conversations", fetch: fetchFunction, polling: false, uiClient });

    await client.getConversationRunState?.(7);
    await uiClient.whenIdle();
    await client.getConversationRunState?.(7);
    await uiClient.whenIdle();

    expect(requests[0].url.searchParams.get("clientId")).toBe("ui-client-0001");
    expect(JSON.parse(requests[0].url.searchParams.get("uiStateJson") ?? "{}")).toEqual({ routePath: "/orders", unsavedFieldReferences: ["f.note"] });
    expect(requests[1].method).toBe("POST");
    expect(requests[1].url.pathname).toBe("/api/agents/conversations/7/clientToolRequests/31/answer");
    expect(requests[1].body).toMatchObject({ clientId: "ui-client-0001", outcome: "OK", text: "# Orders" });
    expect(requests.filter((request) => request.method === "POST")).toHaveLength(1);
  });

  test("a post carries the client id and the UI state", async () => {
    const requests: Array<{ body: unknown }> = [];
    const fetchFunction = vi.fn(async (_input: RequestInfo | URL, init?: RequestInit) => {
      requests.push({ body: init?.body ? JSON.parse(String(init.body)) : undefined });
      return new Response(JSON.stringify({ aiConversation: { id: 7 } }), { status: 200 });
    }) as unknown as typeof fetch;
    const uiClient = new AgentUIClient(adapters, { readUIState: () => ({ routePath: "/orders/42", tab: "items" }) }, "ui-client-0002");
    const client = createAgentsHttpConversationClient({ baseUrl: "/api/agents/conversations", fetch: fetchFunction, polling: false, uiClient });

    await client.postMessage(7, { text: "Where do I change the address?" }).catch(() => undefined);

    expect(requests[0].body).toMatchObject({ clientId: "ui-client-0002", uiState: { routePath: "/orders/42", tab: "items" } });
  });
});
