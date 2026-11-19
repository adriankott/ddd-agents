import { createContext, useContext, useEffect, useState, useSyncExternalStore } from "react";

/**
 * Min width (px) at which the canvas side-panel split is usable. Mirrors the split view's `coversBelowWidth` in
 * ConversationView — below it the open canvas covers the chat, so a canvas widget must render its content inline instead
 * of opening a panel that immediately closes.
 */
export const CANVAS_VIABLE_MIN_WIDTH = 850;

/** The viewport is wide enough for the split (true without a window: a server render lays nothing out). */
const isViewportViable = () => typeof window === "undefined" || window.innerWidth >= CANVAS_VIABLE_MIN_WIDTH;

/**
 * Where the chat reads whether the canvas split fits: the width of the chat's own container. `AgentChatController`
 * measures its chat area and provides it (`CanvasViabilityContext`); without one, the viewport decides.
 */
export interface CanvasViabilitySource {
  /** Whether the split fits now. */
  isViable: () => boolean;
  /** Calls `listener` whenever `isViable()` flips; returns the unsubscribe. */
  subscribe: (listener: () => void) => () => void;
}

/**
 * The width of one element as the canvas viability (`ResizeObserver`). Until the element is laid out (no width yet: not
 * mounted, or a test DOM without layout) the viewport decides, as before.
 */
export class ElementWidthCanvasViability implements CanvasViabilitySource {
  protected elementWidth: number | null = null;
  protected viable: boolean;
  protected readonly listeners = new Set<() => void>();
  protected resizeObserver: ResizeObserver | null = null;
  protected observedElement: Element | null = null;

  constructor(protected readonly minWidth: number = CANVAS_VIABLE_MIN_WIDTH) {
    this.viable = this.isViable();
  }

  isViable = (): boolean => (this.elementWidth !== null && this.elementWidth > 0 ? this.elementWidth >= this.minWidth : isViewportViable());

  subscribe = (listener: () => void): (() => void) => {
    this.listeners.add(listener);
    return () => {
      this.listeners.delete(listener);
    };
  };

  /** Measures `element` from now on (null stops measuring). A ref callback of the container calls it. */
  observe = (element: Element | null): void => {
    if (element === this.observedElement) return;
    this.resizeObserver?.disconnect();
    this.resizeObserver = null;
    this.observedElement = element;
    if (element === null) {
      this.receiveWidth(null);
      return;
    }
    this.receiveWidth(element.getBoundingClientRect().width);
    if (typeof ResizeObserver === "undefined") return;
    this.resizeObserver = new ResizeObserver((entries) => {
      const entry = entries[entries.length - 1];
      if (entry) this.receiveWidth(entry.contentRect.width);
    });
    this.resizeObserver.observe(element);
  };

  /** The viewport changed: an element not laid out yet follows the viewport. */
  receiveViewportResize = (): void => {
    this.notifyWhenFlipped();
  };

  protected receiveWidth(width: number | null): void {
    this.elementWidth = width;
    this.notifyWhenFlipped();
  }

  protected notifyWhenFlipped(): void {
    const viable = this.isViable();
    if (viable === this.viable) return;
    this.viable = viable;
    for (const listener of [...this.listeners]) listener();
  }
}

/** The canvas viability of the chat around (`AgentChatController` provides its chat area's); null = the viewport's. */
export const CanvasViabilityContext = createContext<CanvasViabilitySource | null>(null);

/** Whether the split fits: the source's answer, else the viewport's. Read where no hook can (an event handler). */
export const readCanvasViable = (source: CanvasViabilitySource | null): boolean =>
  source ? source.isViable() : isViewportViable();

const subscribeToNothing = () => () => undefined;

/**
 * True when the chat is wide enough for the canvas side panel: the chat container's width inside a chat that measures
 * it (`CanvasViabilityContext`), else the viewport's. Re-renders the consumer ONLY when the boolean crosses
 * {@link CANVAS_VIABLE_MIN_WIDTH} — NOT on every resize pixel. This matters because the consumers (the canvas widgets,
 * ConversationView) re-mount heavy, self-fetching lists; a width-tracking hook would re-render them on every resize
 * tick and make dragging the window janky.
 */
export const useCanvasViable = (): boolean => {
  const source = useContext(CanvasViabilityContext);
  const sourceViable = useSyncExternalStore(
    source ? source.subscribe : subscribeToNothing,
    source ? source.isViable : isViewportViable,
    source ? source.isViable : isViewportViable,
  );
  const [viewportViable, setViewportViable] = useState(isViewportViable);

  useEffect(() => {
    const onResize = () => {
      if (source instanceof ElementWidthCanvasViability) source.receiveViewportResize();
      setViewportViable((previous) => {
        const next = isViewportViable();
        return next === previous ? previous : next;
      });
    };
    window.addEventListener("resize", onResize);
    // Sync once in case the width changed between the initial render and this effect running.
    onResize();
    return () => window.removeEventListener("resize", onResize);
  }, [source]);

  return source ? sourceViable : viewportViable;
};
