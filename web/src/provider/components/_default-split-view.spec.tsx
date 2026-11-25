import { act, cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, test, vi } from "vitest";
import { DEFAULT_AGENTS_COMPONENTS } from "./default-components";

/**
 * The default split view: the open side pane narrows the main pane, and covers it below `coversBelowWidth` — full
 * width, above the main pane, with the covered main pane inert and a named toggle that stays reachable.
 */

const installManualResizeObserver = () => {
  const callbacks: ResizeObserverCallback[] = [];
  globalThis.ResizeObserver = class {
    constructor(callback: ResizeObserverCallback) {
      callbacks.push(callback);
    }
    observe = vi.fn();
    unobserve = vi.fn();
    disconnect = vi.fn();
  } as unknown as typeof ResizeObserver;
  return (width: number) => {
    for (const callback of callbacks) callback([{ contentRect: { width } } as ResizeObserverEntry], {} as ResizeObserver);
  };
};

describe("DefaultSplitView", () => {
  const originalResizeObserver = globalThis.ResizeObserver;

  afterEach(() => {
    cleanup();
    globalThis.ResizeObserver = originalResizeObserver;
  });

  test("narrows the main pane when wide and covers it below coversBelowWidth", () => {
    const reportWidth = installManualResizeObserver();
    const { SplitView } = DEFAULT_AGENTS_COMPONENTS;
    render(
      <SplitView
        isSideOpen
        coversBelowWidth={850}
        main={{ width: "100%", narrowedWidth: "60%", content: <span>chat</span> }}
        side={{ width: "40%", content: <span>canvas</span> }}
      />,
    );
    const mainPane = screen.getByText("chat").parentElement as HTMLElement;
    const sidePane = screen.getByText("canvas").parentElement as HTMLElement;

    act(() => reportWidth(1200));
    expect(mainPane.style.width).toBe("60%");
    expect(sidePane.style.position).toBe("");

    act(() => reportWidth(480));
    expect(mainPane.style.width).toBe("100%");
    expect(sidePane.style.position).toBe("absolute");
    expect(sidePane.style.width).toBe("100%");
  });

  const renderSplitView = (isSideOpen: boolean, onToggle = vi.fn()) => {
    const { SplitView } = DEFAULT_AGENTS_COMPONENTS;
    return render(
      <SplitView
        isSideOpen={isSideOpen}
        coversBelowWidth={850}
        sideToggle={{ onToggle, verticalPositionPercent: 50 }}
        main={{
          width: "100%",
          narrowedWidth: "60%",
          content: (
            <button type="button" data-testid="main-control">
              open
            </button>
          ),
        }}
        side={{ width: "40%", narrowedWidth: "40%", content: <span>canvas</span> }}
      />,
    );
  };

  test("side by side: the divider toggle is named, carries an icon, and the panes keep their widths", () => {
    const reportWidth = installManualResizeObserver();
    const onToggle = vi.fn();
    renderSplitView(true, onToggle);
    act(() => reportWidth(1200));

    const toggle = screen.getByRole("button", { name: "Close canvas" });
    expect(toggle.getAttribute("aria-expanded")).toBe("true");
    expect(toggle.querySelector("[data-icon='closeCanvas']")).not.toBeNull();
    expect(toggle.style.position).toBe("");

    const mainPane = screen.getByTestId("main-control").parentElement as HTMLElement;
    const sidePane = screen.getByText("canvas").parentElement as HTMLElement;
    expect(mainPane.style.width).toBe("60%");
    expect(mainPane.hasAttribute("inert")).toBe(false);
    expect(mainPane.getAttribute("aria-hidden")).toBeNull();
    expect(sidePane.style.width).toBe("40%");
    expect(sidePane.style.position).toBe("");
    expect(sidePane.getAttribute("tabindex")).toBeNull();

    toggle.click();
    expect(onToggle).toHaveBeenCalledTimes(1);
  });

  test("side by side and closed: the toggle names the re-open", () => {
    const reportWidth = installManualResizeObserver();
    renderSplitView(false);
    act(() => reportWidth(1200));
    const toggle = screen.getByRole("button", { name: "Re-open canvas" });
    expect(toggle.getAttribute("aria-expanded")).toBe("false");
    expect(toggle.querySelector("[data-icon='maximize']")).not.toBeNull();
  });

  test("covered: the canvas takes the full width above the chat, the chat is inert, the named toggle stays", () => {
    const reportWidth = installManualResizeObserver();
    const onToggle = vi.fn();
    renderSplitView(true, onToggle);
    act(() => reportWidth(480));

    const mainPane = screen.getByTestId("main-control").parentElement as HTMLElement;
    const sidePane = screen.getByText("canvas").parentElement as HTMLElement;
    expect(mainPane.hasAttribute("inert")).toBe(true);
    expect(mainPane.getAttribute("aria-hidden")).toBe("true");
    expect(mainPane.style.width).toBe("100%");

    expect(sidePane.style.position).toBe("absolute");
    expect(sidePane.style.width).toBe("100%");
    expect(sidePane.style.maxWidth).toBe("100%");
    expect(sidePane.style.boxSizing).toBe("border-box");
    expect(sidePane.style.zIndex).toBe("20");
    expect(sidePane.style.isolation).toBe("isolate");
    expect(sidePane.style.background).not.toBe("");

    const toggle = screen.getByRole("button", { name: "Close canvas" });
    expect(sidePane.contains(toggle)).toBe(false);
    expect(mainPane.contains(toggle)).toBe(false);
    expect(toggle.style.position).toBe("absolute");
    expect(Number(toggle.style.zIndex)).toBeGreaterThan(20);
    toggle.click();
    expect(onToggle).toHaveBeenCalledTimes(1);
  });

  test("covering moves the focus into the canvas and back to the chat when the canvas closes", () => {
    const reportWidth = installManualResizeObserver();
    const view = renderSplitView(false);
    act(() => reportWidth(480));
    const mainControl = screen.getByTestId("main-control");
    act(() => mainControl.focus());
    expect(document.activeElement).toBe(mainControl);

    const { SplitView } = DEFAULT_AGENTS_COMPONENTS;
    const rerenderWith = (isSideOpen: boolean) =>
      view.rerender(
        <SplitView
          isSideOpen={isSideOpen}
          coversBelowWidth={850}
          sideToggle={{ onToggle: vi.fn() }}
          main={{
            width: "100%",
            narrowedWidth: "60%",
            content: (
              <button type="button" data-testid="main-control">
                open
              </button>
            ),
          }}
          side={{ width: "40%", content: <span>canvas</span> }}
        />,
      );
    act(() => rerenderWith(true));
    const sidePane = screen.getByText("canvas").parentElement as HTMLElement;
    expect(document.activeElement).toBe(sidePane);

    act(() => rerenderWith(false));
    expect(document.activeElement).toBe(screen.getByTestId("main-control"));
  });
});
