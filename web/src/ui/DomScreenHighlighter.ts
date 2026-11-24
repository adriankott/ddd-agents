import type { ScreenHighlighter, ScreenMarkResult } from "./client-tools";
import { AGENT_ATTRIBUTES, labelOfElement, type ScreenReferenceRegistry } from "./DomScreenReader";

/** How long a mark stays. */
export const MARK_VISIBLE_MS = 8000;

/** The most characters of a note. */
const MAX_NOTE_CHARS = 120;

/** A region of the viewport in CSS pixels (a `DOMRect` fits). */
export interface ScreenOccludingRect {
  top: number;
  left: number;
  right: number;
  bottom: number;
}

/** What a mark did, for the application (e.g. to shrink or move its agent window). */
export interface ScreenHighlightEvent {
  /** The references that were marked */
  references: string[];
  /** The marked elements, the first one scrolled into view */
  elements: Element[];
  /** The region the application reported as covering the page (its agent window), or null */
  occludingRect: ScreenOccludingRect | null;
  /** True when the first element stays covered by that region after the scroll (too tall for the free band, or a side panel over it) */
  coveredAfterScroll: boolean;
}

/** How to scroll a marked element so that it lands beside a covering region. */
export interface HighlightScrollPlan {
  /** Scroll margins applied for the one `scrollIntoView` call, in pixels */
  scrollMarginTop: number;
  scrollMarginBottom: number;
  /** True when the element cannot be scrolled out from under the region */
  coveredAfterScroll: boolean;
}

/** A region at least this share of the viewport height is a side panel: vertical scrolling cannot clear it. */
export const SIDE_PANEL_MIN_HEIGHT_SHARE = 0.6;

/** The space kept between a marked element and the edge of the free band. */
const FREE_BAND_PADDING_PX = 16;

export interface DomScreenHighlighterOptions {
  registry: ScreenReferenceRegistry;
  /** The sentence the live region reads, e.g. "The assistant marked: End date" (translated by the application) */
  announce: (labels: string[]) => string;
  /** The region that covers the page now (the agent window in the viewport), or null; marks are scrolled beside it */
  getOccludingRect?: () => ScreenOccludingRect | null;
  /** Called after each mark, e.g. to make the agent window smaller or move it when the element stays covered */
  onHighlight?: (event: ScreenHighlightEvent) => void;
}

/**
 * Where a marked element must scroll to stay clear of a covering region: a sheet at the bottom (or top) of the
 * viewport that overlaps the element horizontally adds its height as a scroll margin, so `block: 'center'` centers the
 * element in the free band; a side panel (taller than {@link SIDE_PANEL_MIN_HEIGHT_SHARE} of the viewport) over the
 * element cannot be cleared by scrolling and is reported as covered.
 */
export const planHighlightScroll = (
  targetRect: ScreenOccludingRect,
  occludingRect: ScreenOccludingRect | null,
  viewportHeight: number,
): HighlightScrollPlan => {
  const plan: HighlightScrollPlan = { scrollMarginTop: 0, scrollMarginBottom: 0, coveredAfterScroll: false };
  if (!occludingRect || occludingRect.bottom <= occludingRect.top || occludingRect.right <= occludingRect.left) return plan;
  const overlapsHorizontally = occludingRect.left < targetRect.right && occludingRect.right > targetRect.left;
  if (!overlapsHorizontally) return plan;
  const occludingHeight = Math.min(occludingRect.bottom, viewportHeight) - Math.max(occludingRect.top, 0);
  if (occludingHeight <= 0) return plan;
  if (occludingHeight >= viewportHeight * SIDE_PANEL_MIN_HEIGHT_SHARE) {
    plan.coveredAfterScroll = true;
    return plan;
  }
  const targetHeight = targetRect.bottom - targetRect.top;
  const occludesBottom = (occludingRect.top + occludingRect.bottom) / 2 >= viewportHeight / 2;
  if (occludesBottom) {
    plan.scrollMarginBottom = Math.max(0, viewportHeight - occludingRect.top);
    plan.coveredAfterScroll = targetHeight > Math.max(0, occludingRect.top) - FREE_BAND_PADDING_PX;
  } else {
    plan.scrollMarginTop = Math.max(0, occludingRect.bottom);
    plan.coveredAfterScroll = targetHeight > viewportHeight - Math.max(0, occludingRect.bottom) - FREE_BAND_PADDING_PX;
  }
  return plan;
};

/**
 * Marks elements with a calm outline (the theme's `--ring`) in an overlay above the page, a short note beside the
 * first one, and one sentence in a polite live region. The overlay never takes a click (`pointer-events: none`) and is
 * never read (`data-agent-skip`). Marks follow scrolling and leave after {@link MARK_VISIBLE_MS}, on Escape or on the
 * next mark. With `getOccludingRect` the first element is scrolled beside the application's agent window, and a
 * mark that stays covered counts as out of view; `onHighlight` tells the application after every mark.
 */
export class DomScreenHighlighter implements ScreenHighlighter {
  protected overlay: HTMLElement | null = null;

  protected liveRegion: HTMLElement | null = null;

  protected frame = 0;

  protected timer: ReturnType<typeof setTimeout> | null = null;

  protected marked: { element: Element; box: HTMLElement }[] = [];

  protected note: HTMLElement | null = null;

  constructor(protected readonly options: DomScreenHighlighterOptions) {}

  mark(references: string[], note: string | null): ScreenMarkResult {
    this.clear();
    const result: ScreenMarkResult = { marked: {}, missing: [], outOfView: [], collapsed: [] };
    const targets: Element[] = [];
    for (const reference of references) {
      let element = this.options.registry.find(reference) ?? this.options.registry.findAnchor(reference);
      if (!element) {
        result.missing.push(reference);
        continue;
      }
      // inside a closed section: mark what opens it
      const closedDisclosure = element.closest('[data-state="closed"]');
      if (closedDisclosure && closedDisclosure !== element) {
        const trigger = closedDisclosure.parentElement?.querySelector('[aria-expanded="false"]');
        if (trigger) {
          element = trigger;
          result.collapsed.push(reference);
        }
      }
      const rect = element.getBoundingClientRect();
      if (rect.bottom < 0 || rect.top > window.innerHeight) result.outOfView.push(reference);
      result.marked[reference] = labelOfElement(element) || reference;
      targets.push(element);
    }
    if (targets.length === 0) return result;
    const occludingRect = this.readOccludingRect();
    const plan = this.scrollIntoFreeBand(targets[0], occludingRect);
    const firstReference = Object.keys(result.marked)[0];
    if (plan.coveredAfterScroll && firstReference !== undefined && !result.outOfView.includes(firstReference)) {
      result.outOfView.push(firstReference);
    }
    this.draw(targets, note ? note.slice(0, MAX_NOTE_CHARS) : null);
    this.announce(this.options.announce(Object.values(result.marked)));
    try {
      this.options.onHighlight?.({
        references: Object.keys(result.marked),
        elements: targets,
        occludingRect,
        coveredAfterScroll: plan.coveredAfterScroll,
      });
    } catch {
      // the application's hook never breaks the mark
    }
    return result;
  }

  /** The application's covering region now; a failing callback counts as none. */
  protected readOccludingRect(): ScreenOccludingRect | null {
    try {
      return this.options.getOccludingRect?.() ?? null;
    } catch {
      return null;
    }
  }

  /** Scrolls the element to the center of the band the covering region leaves free (the whole viewport without one). */
  protected scrollIntoFreeBand(element: Element, occludingRect: ScreenOccludingRect | null): HighlightScrollPlan {
    const plan = planHighlightScroll(element.getBoundingClientRect(), occludingRect, window.innerHeight);
    const style = (element as HTMLElement).style as CSSStyleDeclaration | undefined;
    const marginTopBefore = style?.scrollMarginTop ?? '';
    const marginBottomBefore = style?.scrollMarginBottom ?? '';
    if (style) {
      if (plan.scrollMarginTop > 0) style.scrollMarginTop = `${plan.scrollMarginTop}px`;
      if (plan.scrollMarginBottom > 0) style.scrollMarginBottom = `${plan.scrollMarginBottom}px`;
    }
    try {
      // the scroll position is computed in the call: the margins can go right after it
      element.scrollIntoView({ block: 'center', behavior: 'smooth' });
    } finally {
      if (style) {
        style.scrollMarginTop = marginTopBefore;
        style.scrollMarginBottom = marginBottomBefore;
      }
    }
    return plan;
  }

  clear(): void {
    if (this.timer) clearTimeout(this.timer);
    this.timer = null;
    if (this.frame) cancelAnimationFrame(this.frame);
    this.frame = 0;
    this.overlay?.remove();
    this.overlay = null;
    this.marked = [];
    this.note = null;
    document.removeEventListener('keydown', this.clearOnEscape);
  }

  protected clearOnEscape = (event: KeyboardEvent): void => {
    if (event.key === 'Escape') this.clear();
  };

  protected draw(elements: Element[], note: string | null): void {
    const overlay = document.createElement('div');
    overlay.setAttribute(AGENT_ATTRIBUTES.skip, '');
    overlay.setAttribute('aria-hidden', 'true');
    overlay.style.cssText = 'position:fixed;inset:0;pointer-events:none;z-index:2147483000;';
    document.body.appendChild(overlay);
    this.overlay = overlay;
    this.marked = elements.map(element => {
      const box = document.createElement('div');
      box.style.cssText =
        'position:fixed;border-radius:8px;box-shadow:0 0 0 2px var(--ring, #06d6e3),0 0 0 6px color-mix(in oklab, var(--ring, #06d6e3) 25%, transparent);transition:opacity 200ms ease;';
      overlay.appendChild(box);
      return { element, box };
    });
    if (note) {
      const noteElement = document.createElement('div');
      noteElement.textContent = note;
      noteElement.style.cssText =
        'position:fixed;max-width:280px;padding:6px 10px;border-radius:8px;font-size:13px;line-height:1.35;background:var(--popover, #fff);color:var(--popover-foreground, #0b0948);box-shadow:0 4px 16px rgb(0 0 0 / 0.16);';
      overlay.appendChild(noteElement);
      this.note = noteElement;
    }
    const follow = (): void => {
      for (const { element, box } of this.marked) {
        const rect = element.getBoundingClientRect();
        box.style.left = `${rect.left - 4}px`;
        box.style.top = `${rect.top - 4}px`;
        box.style.width = `${rect.width + 8}px`;
        box.style.height = `${rect.height + 8}px`;
        box.style.opacity = element.isConnected ? '1' : '0';
      }
      if (this.note && this.marked[0]) {
        const rect = this.marked[0].element.getBoundingClientRect();
        // the note stays out of the agent window: a sheet below the element ends the room below
        const occludingRect = this.readOccludingRect();
        const coversBelow =
          occludingRect !== null &&
          occludingRect.left < rect.right &&
          occludingRect.right > rect.left &&
          occludingRect.top >= rect.bottom;
        const roomBottom = coversBelow ? occludingRect.top : window.innerHeight;
        const below = rect.bottom + 10 + 60 < roomBottom;
        this.note.style.left = `${Math.max(8, Math.min(rect.left, window.innerWidth - 296))}px`;
        this.note.style.top = below ? `${rect.bottom + 10}px` : `${Math.max(8, rect.top - 70)}px`;
      }
      this.frame = requestAnimationFrame(follow);
    };
    follow();
    document.addEventListener('keydown', this.clearOnEscape);
    this.timer = setTimeout(() => this.clear(), MARK_VISIBLE_MS);
  }

  protected announce(sentence: string): void {
    if (!this.liveRegion?.isConnected) {
      const liveRegion = document.createElement('div');
      liveRegion.setAttribute('role', 'status');
      liveRegion.setAttribute('aria-live', 'polite');
      liveRegion.setAttribute(AGENT_ATTRIBUTES.skip, '');
      liveRegion.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap;';
      document.body.appendChild(liveRegion);
      this.liveRegion = liveRegion;
    }
    this.liveRegion.textContent = '';
    const liveRegion = this.liveRegion;
    setTimeout(() => {
      liveRegion.textContent = sentence;
    }, 50);
  }
}
