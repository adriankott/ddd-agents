import { describe, expect, test, vi } from "vitest";
import { AgentsClientError } from "../../contract/conversation";
import { createAgentsHttpConversationClient, type AgentsHttpConversationClientOptions } from "./createAgentsHttpConversationClient";

/**
 * `createAgentsHttpConversationClient`: each port function calls its route of the module's controller traits with the
 * query and body the DTOs read, maps the wire onto the contract, turns every failure into an `AgentsClientError`, and
 * comes with the polling default over the run-state read.
 */

interface RecordedRequest {
  url: URL;
  method: string;
  body: unknown;
  headers: Headers;
}

/** A fetch that answers every request with the next scripted answer and records what it got. */
const createScriptedFetch = (answers: Array<{ status?: number; body?: unknown; headers?: Record<string, string> }>) => {
  const requests: RecordedRequest[] = [];
  const fetchFunction = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    requests.push({
      url: new URL(String(input), "https://host.test"),
      method: init?.method ?? "GET",
      body: init?.body ? JSON.parse(String(init.body)) : undefined,
      headers: new Headers(init?.headers),
    });
    const answer = answers.shift() ?? { body: {} };
    return new Response(answer.body === undefined ? "" : JSON.stringify(answer.body), {
      status: answer.status ?? 200,
      headers: answer.headers,
    });
  });
  return { fetchFunction: fetchFunction as unknown as typeof fetch, requests };
};

const buildClient = (
  answers: Array<{ status?: number; body?: unknown; headers?: Record<string, string> }>,
  options: Partial<AgentsHttpConversationClientOptions> = {},
) => {
  const { fetchFunction, requests } = createScriptedFetch(answers);
  const client = createAgentsHttpConversationClient({
    baseUrl: "/api/agents/conversations/",
    fetch: fetchFunction,
    polling: false,
    ...options,
  });
  return { client, requests };
};

const wireMessage = {
  id: 41,
  aiConversationId: 7,
  indexInConversation: 3,
  role: "ASSISTANT",
  messageType: "TOOL_CALL",
  status: "COMPLETED",
  authorType: "ASSISTANT",
  signalSource: null,
  message: "Looking it up.",
  createdDate: "2026-11-01",
  toolCall: { toolCallId: "call-1", name: "delegate", arguments: { tasks: [] }, status: "RUNNING" },
  delegatedConversations: { elements: [{ id: 90, runState: "RUNNING" }] },
  delegationResults: { subAgent: { currentRunNumber: 2, runs: { elements: [{ runNumber: 2, status: "running" }] } } },
};

describe("createAgentsHttpConversationClient", () => {
  test("reads the newest page newest first with the delegated children and maps each row", async () => {
    const { client, requests } = buildClient([{ body: { messages: { elements: [wireMessage] }, hasMoreOlder: true } }]);

    const page = await client.getNewestMessagesPage(7, 20);

    expect(requests[0].url.pathname).toBe("/api/agents/conversations/7/messages");
    expect(requests[0].url.searchParams.get("orderBy")).toBe("indexInConversation desc");
    expect(requests[0].url.searchParams.get("top")).toBe("20");
    expect(requests[0].url.searchParams.get("expand")).toBe("delegatedConversations(select=id,runState)");
    expect(requests[0].url.searchParams.has("filters")).toBe(false);
    expect(page.hasMoreOlder).toBe(true);
    expect(page.messages[0]).toEqual({
      id: 41,
      conversationId: 7,
      indexInConversation: 3,
      role: "ASSISTANT",
      messageType: "TOOL_CALL",
      status: "COMPLETED",
      authorType: "ASSISTANT",
      signalSource: null,
      message: "Looking it up.",
      createdDate: "2026-11-01",
      toolCall: { toolCallId: "call-1", name: "delegate", arguments: { tasks: [] }, status: "RUNNING" },
      delegatedConversations: [{ id: 90, runState: "RUNNING" }],
      subAgent: { currentRunNumber: 2, runs: [{ runNumber: 2, status: "running" }] },
    });
  });

  test("an older page and a changed-messages read filter by index and id", async () => {
    const { client, requests } = buildClient([{ body: { messages: [], hasMoreOlder: false } }, { body: { messages: [] } }]);

    await client.getOlderMessagesPage(7, 12, 20);
    await client.getChangedMessages(7, { highestKnownId: 55, fromIndex: 9 });

    expect(requests[0].url.searchParams.get("filters")).toBe("indexInConversation lt '12'");
    expect(requests[1].url.searchParams.get("filters")).toBe("(id gt '55' or indexInConversation ge '9')");
    expect(requests[1].url.searchParams.get("top")).toBe("50");
  });

  test("maps a conversation: summary, change history, progress steps, agent space", async () => {
    const { client, requests } = buildClient([
      {
        body: {
          aiConversation: {
            id: 7,
            agentSpaceId: 3,
            runState: "IDLE",
            agentTurnOwedAt: null,
            summary: { title: "Visits", text: "About visits", manuallyEdited: true },
            changeHistory: { createdTime: "2026-11-01 10:00:00", modifiedTime: "2026-11-02 10:00:00" },
            purposeName: null,
            initiatorType: "SCHEDULE",
            scheduleId: 5,
            entryPointKey: null,
            customerProgressSteps: { elements: [{ key: "a", customerLabel: "First", status: "active" }] },
          },
        },
      },
    ]);

    const conversation = await client.getConversationMetadata(7);

    expect(requests[0].url.pathname).toBe("/api/agents/conversations/7");
    expect(conversation).toEqual({
      id: 7,
      agentSpaceKey: 3,
      runState: "IDLE",
      agentTurnOwedAt: null,
      title: "Visits",
      titleManuallyEdited: true,
      summary: "About visits",
      created: "2026-11-01 10:00:00",
      updated: "2026-11-02 10:00:00",
      purposeName: null,
      initiatorType: "SCHEDULE",
      scheduleId: 5,
      entryPointKey: null,
      progressSteps: [{ key: "a", label: "First", status: "active" }],
    });
  });

  test("lists the root conversations of an agent space newest first, without untitled ones unless asked", async () => {
    const conversations = {
      aiConversations: { elements: [{ id: 2, summary: { title: "Titled" } }, { id: 1, summary: null }] },
    };
    const { client, requests } = buildClient([{ body: conversations }, { body: conversations }]);

    const titled = await client.listConversations(3, { paging: { top: 50, skip: 0 } });
    const all = await client.listConversations(0, { includeUnsummarized: true });

    expect(requests[0].url.pathname).toBe("/api/agents/conversations/listAllConversations");
    expect(requests[0].url.searchParams.get("filters")).toBe("rootConversationId eq null and agentSpaceId eq '3'");
    expect(requests[0].url.searchParams.get("orderBy")).toBe("id desc");
    expect(requests[0].url.searchParams.get("top")).toBe("50");
    expect(requests[1].url.searchParams.get("filters")).toBe("rootConversationId eq null");
    expect(titled.map((conversation) => conversation.id)).toEqual([2]);
    expect(all.map((conversation) => conversation.id)).toEqual([2, 1]);
  });

  test("starts at /start without an agent space and at /start/{key} with one; a reopened start has no messages", async () => {
    const { client, requests } = buildClient([
      { body: { aiConversation: { id: 8, messages: { elements: [wireMessage] } }, reopenedExistingConversation: false } },
      { body: { aiConversation: { id: 9, messages: { elements: [wireMessage] } }, reopenedExistingConversation: true } },
    ]);

    const started = await client.startConversation({ agentSpaceKey: 0, text: "Hi", model: "model-a" });
    const reopened = await client.startConversation({ agentSpaceKey: 4, text: "", startSignalText: "Begin", entryPointKey: "hub.a" });

    expect(requests[0].method).toBe("POST");
    expect(requests[0].url.pathname).toBe("/api/agents/conversations/start");
    expect(requests[0].url.searchParams.get("aiModelName")).toBe("model-a");
    expect(requests[0].body).toEqual({ aiConversationMessage: { message: "Hi" } });
    expect(requests[1].url.pathname).toBe("/api/agents/conversations/start/4");
    expect(requests[1].body).toEqual({ aiConversationMessage: { message: "" }, signalText: "Begin", entryPointKey: "hub.a" });
    expect(started.messages).toHaveLength(1);
    expect(started.reopenedExisting).toBe(false);
    expect(reopened).toMatchObject({ reopenedExisting: true, messages: [] });
  });

  test("a post carries its attachments in the text, the model and the host's extra parameters", async () => {
    const { client, requests } = buildClient([{ body: { aiConversation: { id: 7, messages: [wireMessage] } } }], {
      extraRequestParameters: (operation) => (operation === "postMessage" ? { partition: "side" } : {}),
    });

    const posted = await client.postMessage(7, {
      text: "See this",
      model: "model-b",
      attachments: [{ fileId: 1, fileName: "a.png", publicUrl: "https://files.test/a.png" }],
    });

    expect(requests[0].url.pathname).toBe("/api/agents/conversations/7/postMessage");
    expect(requests[0].url.searchParams.get("aiModelName")).toBe("model-b");
    expect(requests[0].url.searchParams.get("partition")).toBe("side");
    expect(requests[0].body).toEqual({
      aiConversationMessage: { message: "See this\n\n[Attached file: a.png — https://files.test/a.png]" },
    });
    expect(client.composeSentMessageText?.("See this", [{ fileId: 1, fileName: "a.png", publicUrl: "https://files.test/a.png" }])).toBe(
      "See this\n\n[Attached file: a.png — https://files.test/a.png]",
    );
    expect(posted.messages[0].id).toBe(41);
  });

  test("signal, retry, title, delete and the searches use their routes", async () => {
    const { client, requests } = buildClient([
      { body: {} },
      { body: {} },
      { body: { aiConversation: { id: 7, summary: { title: "New", manuallyEdited: true } } } },
      { body: { message: "Entity deleted" } },
      { body: { conversations: [{ conversationId: 7, title: "T", relevance: 0.5, hits: [{ messageId: 1, excerpt: "x", relevance: 0.4 }] }] } },
      { body: { hits: [{ messageId: 2, excerpt: "y", relevance: 0.3 }] } },
      { body: { turnMessageId: 40, turnRunning: true, elements: { elements: [{ toolCallMessageId: 41, state: "running", kind: "tool", line: "Reading" }] } } },
    ]);

    await client.injectSignal(7, { text: "Clicked B", source: "FRONTEND_WIDGET_CLICK" });
    await client.retryConversation(7);
    const renamed = await client.renameConversation(7, "New");
    await client.deleteConversation(7);
    const groups = await client.searchConversations("visits", { limit: 10, excerptLength: 420 });
    const hits = await client.searchConversationMessages(7, "visits");
    const activity = await client.getConversationActivity(7, 40);

    expect(requests.map((request) => `${request.method} ${request.url.pathname}`)).toEqual([
      "POST /api/agents/conversations/7/injectSignal",
      "POST /api/agents/conversations/7/retry",
      "PATCH /api/agents/conversations/7/title",
      "DELETE /api/agents/conversations/7",
      "GET /api/agents/conversations/search",
      "GET /api/agents/conversations/7/messages/search",
      "GET /api/agents/conversations/7/activity",
    ]);
    expect(requests[0].body).toEqual({ signalText: "Clicked B", signalSource: "FRONTEND_WIDGET_CLICK" });
    expect(requests[2].body).toEqual({ title: "New" });
    expect(requests[4].url.searchParams.get("query")).toBe("visits");
    expect(requests[4].url.searchParams.get("limit")).toBe("10");
    expect(requests[4].url.searchParams.get("excerptChars")).toBe("420");
    expect(requests[6].url.searchParams.get("turnMessageId")).toBe("40");
    expect(renamed).toMatchObject({ title: "New", titleManuallyEdited: true });
    expect(groups[0].hits[0]).toMatchObject({ messageId: 1, excerpt: "x", relevance: 0.4 });
    expect(hits[0]).toMatchObject({ messageId: 2, excerpt: "y" });
    expect(activity).toEqual({
      turnMessageId: 40,
      turnRunning: true,
      elements: [
        { toolCallMessageId: 41, batchId: null, state: "running", kind: "tool", childCount: null, helperIndex: null, line: "Reading" },
      ],
    });
  });

  test("reads the run state and offers the model picker only for a controller with its route", async () => {
    const { client, requests } = buildClient(
      [
        { body: { id: 7, runState: "RUNNING", agentTurnOwedAt: null, lastMessageId: 41, title: "T", titleManuallyEdited: false, summary: null, updated: "u" } },
        { body: { models: { elements: [{ name: "m", displayName: "M", description: "d", strengthLevel: 2, speedLevel: 3, creditsMultiplier: 1.5 }] } } },
      ],
      { hasSelectableModelsRoute: true },
    );
    const withoutModels = buildClient([]).client;

    const runState = await client.getConversationRunState?.(7);
    const models = await client.getSelectableModels?.();

    expect(requests[0].url.pathname).toBe("/api/agents/conversations/7/runState");
    expect(runState).toEqual({ id: 7, runState: "RUNNING", agentTurnOwedAt: null, lastMessageId: 41, title: "T", titleManuallyEdited: false, summary: null, updated: "u" });
    expect(requests[1].url.pathname).toBe("/api/agents/conversations/selectable-models");
    expect(models).toEqual([{ name: "m", displayName: "M", description: "d", strengthLevel: 2, speedLevel: 3, creditsMultiplier: 1.5 }]);
    expect(withoutModels.getSelectableModels).toBeUndefined();
  });

  test("every failure rejects with an AgentsClientError of the matching kind", async () => {
    const { client } = buildClient([
      { status: 404, body: { error: "AIConversation 7 not found." } },
      { status: 400, body: { error: "closed", errorCode: "CONVERSATION_CLOSED" } },
      { status: 429, body: { error: "Rate limit exceeded." }, headers: { "Retry-After": "30" } },
      { status: 403, body: { error: "No access" } },
      { status: 500, body: "oops" },
    ]);

    const rejections = await Promise.all(
      [
        () => client.getConversationMetadata(7),
        () => client.postMessage(7, { text: "x" }),
        () => client.postMessage(7, { text: "x" }),
        () => client.getConversationMetadata(7),
        () => client.getConversationMetadata(7),
      ].map(async (call) => call().catch((failure: unknown) => failure)),
    );

    expect(rejections.every((rejection) => rejection instanceof AgentsClientError)).toBe(true);
    expect((rejections as AgentsClientError[]).map((rejection) => rejection.kind)).toEqual([
      "notFound",
      "closed",
      "rateLimit",
      "forbidden",
      "network",
    ]);
    expect((rejections[2] as AgentsClientError).retryAfterSeconds).toBe(30);
    expect((rejections[1] as AgentsClientError).code).toBe("CONVERSATION_CLOSED");
  });

  test("a transport failure is a network error and a cancelled request carries REQUEST_ABORTED", async () => {
    const failingFetch = vi
      .fn()
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockRejectedValueOnce(Object.assign(new Error("aborted"), { name: "AbortError" }));
    const client = createAgentsHttpConversationClient({ baseUrl: "/c", fetch: failingFetch as unknown as typeof fetch, polling: false });

    const networkFailure = await client.getConversationMetadata(1).catch((failure: unknown) => failure as AgentsClientError);
    const abortedFailure = await client.getConversationMetadata(1).catch((failure: unknown) => failure as AgentsClientError);

    expect(networkFailure.kind).toBe("network");
    expect(abortedFailure).toMatchObject({ kind: "other", code: "REQUEST_ABORTED" });
  });

  test("reads the fetch options per request and sends JSON", async () => {
    let token = "first";
    const { client, requests } = buildClient([{ body: {} }, { body: {} }], {
      fetchOptions: () => ({ headers: { Authorization: `Bearer ${token}` }, credentials: "include" }),
    });

    await client.retryConversation(1);
    token = "second";
    await client.retryConversation(1);

    expect(requests[0].headers.get("Authorization")).toBe("Bearer first");
    expect(requests[1].headers.get("Authorization")).toBe("Bearer second");
    expect(requests[0].headers.get("Accept")).toBe("application/json");
  });

  test("comes with live updates polled over the run-state read", async () => {
    const { fetchFunction, requests } = createScriptedFetch([
      { body: { id: 7, runState: "IDLE", lastMessageId: 41, title: null, summary: null, updated: "u" } },
    ]);
    const client = createAgentsHttpConversationClient({
      baseUrl: "/c",
      fetch: fetchFunction,
      polling: { documentVisibility: null },
    });
    const onSignal = vi.fn();

    const unsubscribe = client.subscribeToConversationChanges?.(7, onSignal, vi.fn());
    await vi.waitFor(() => expect(onSignal).toHaveBeenCalledTimes(1));
    unsubscribe?.();

    expect(requests[0].url.pathname).toBe("/c/7/runState");
    expect(onSignal).toHaveBeenCalledWith({ changeCounter: 0, lastMessageId: 41, runStatus: "idle", summaryUpdatedAt: 0 });
  });
});
