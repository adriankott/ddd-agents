import { type AnchorHTMLAttributes, type MouseEvent as ReactMouseEvent, useMemo } from "react";
import { useAgentsEnvironment } from "./AgentsProvider";
import type { AgentsEnvironment } from "./environment";

/** Opens a link the package's default way: a new tab, without opener or referrer. */
const openInNewTab = (href: string): void => {
  window.open(href, "_blank", "noopener,noreferrer");
};

/**
 * Opens a link through the environment's `onNavigate` (the host decides: an in-app route, a new tab, nothing), else in
 * a new tab. `event` is the click that asked for it, when there is one.
 */
export const openLinkThroughEnvironment = (
  environment: Pick<AgentsEnvironment, "onNavigate">,
  href: string,
  event: MouseEvent | null,
): void => {
  if (environment.onNavigate?.(href, event) === true) return;
  openInNewTab(href);
};

const readNativeEvent = (event: ReactMouseEvent | MouseEvent | undefined): MouseEvent | null =>
  event === undefined ? null : "nativeEvent" in event ? event.nativeEvent : event;

/** How package code opens a link: through the host's `onNavigate`, else in a new tab. */
export interface AgentsLinkNavigation {
  /** Opens `href` (a link opened by code, e.g. a button: the package's `window.open` places). */
  openLink: (href: string, event?: ReactMouseEvent | MouseEvent) => void;
  /**
   * The click handler of an anchor that opens in a new tab: hands the click to the host's `onNavigate` and keeps the
   * browser from following the anchor when the host took it.
   */
  handleLinkClick: (event: ReactMouseEvent<HTMLAnchorElement>, href?: string) => void;
}

/** The link navigation of this subtree (`AgentsEnvironment.onNavigate`). */
export const useAgentsLinkNavigation = (): AgentsLinkNavigation => {
  const { onNavigate } = useAgentsEnvironment();
  return useMemo(
    () => ({
      openLink: (href, event) => openLinkThroughEnvironment({ onNavigate }, href, readNativeEvent(event)),
      handleLinkClick: (event, href) => {
        const target = href ?? event.currentTarget.getAttribute("href") ?? "";
        if (!target || !onNavigate) return;
        if (onNavigate(target, event.nativeEvent) === true) event.preventDefault();
      },
    }),
    [onNavigate],
  );
};

export type AgentsLinkProps = AnchorHTMLAttributes<HTMLAnchorElement> & {
  /** The hast node react-markdown passes to a custom component; never put on the DOM element. */
  node?: unknown;
};

/**
 * The anchor every package link renders: a new tab without opener or referrer, unless the host's `onNavigate` takes
 * the click (an in-app route). A host's own widget uses it for the same behaviour.
 */
export const AgentsLink = (props: AgentsLinkProps) => {
  const { node, href, onClick, children, ...anchorProps } = props;
  const { handleLinkClick } = useAgentsLinkNavigation();
  return (
    <a
      target="_blank"
      rel="noopener noreferrer"
      {...anchorProps}
      href={href}
      onClick={(event) => {
        onClick?.(event);
        if (!event.defaultPrevented && href) handleLinkClick(event, href);
      }}
    >
      {children}
    </a>
  );
};
