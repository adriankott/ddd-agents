import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { installLiveWidgetRequests } from "./live-widget-requests";
import { getWidgetImageCaptureReadPathPrefix } from "../provider/environment";
import type { WidgetImageRenderBootstrap } from "./widget-image-render.types";

const bootstrap = (authToken: string | null = "capture-token"): WidgetImageRenderBootstrap => ({
  snapshotId: "s",
  attemptId: "a",
  inputSha256: "h",
  renderVersion: "1",
  userId: 1,
  siteId: 2,
  expiresAt: Date.now() / 1000 + 60,
  authToken,
  input: { widgetName: "ReviewsWidget", props: {}, hydration: {}, settings: {}, locale: "en" },
});

const readiness = () => {
  const failures: string[] = [];
  return {
    failures,
    isFailed: () => failures.length > 0,
    fail: (code: string) => void failures.push(code),
    beginRequest: () => () => undefined,
  };
};

describe("installLiveWidgetRequests — fetch reads", () => {
  let backendFetch: ReturnType<typeof vi.fn>;
  let uninstall: () => void;

  beforeEach(() => {
    backendFetch = vi.fn(async () => new Response('{"ok":true}', { status: 200 }));
    window.fetch = backendFetch as unknown as typeof window.fetch;
  });
  afterEach(() => uninstall?.());

  it("reads the capture path with the capture token and nothing of the page", async () => {
    const state = readiness();
    uninstall = installLiveWidgetRequests(bootstrap(), state as never);
    const response = await window.fetch(`${getWidgetImageCaptureReadPathPrefix()}reviews?debug=1&noCache=1&top=5`, {
      headers: { Authorization: "Bearer page-token" },
    });

    expect(await response.json()).toEqual({ ok: true });
    const [url, init] = backendFetch.mock.calls[0] as [string, RequestInit];
    expect(new URL(url).search).toBe("?top=5");
    expect(init.credentials).toBe("omit");
    expect((init.headers as Record<string, string>).Authorization).toBe("Bearer capture-token");
    expect(state.failures).toEqual([]);
  });

  it("refuses a write, a read without capture token and any other path, and fails the capture", async () => {
    for (const [authToken, url, init] of [
      ["capture-token", `${getWidgetImageCaptureReadPathPrefix()}reviews`, { method: "POST", body: "{}" }],
      [null, `${getWidgetImageCaptureReadPathPrefix()}reviews`, undefined],
      ["capture-token", "/graphql", undefined],
    ] as const) {
      const state = readiness();
      uninstall = installLiveWidgetRequests(bootstrap(authToken), state as never);
      await expect(window.fetch(url, init)).rejects.toThrow();
      expect(state.failures.length).toBe(1);
      uninstall();
    }
    expect(backendFetch).not.toHaveBeenCalled();
  });
});
