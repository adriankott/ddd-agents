import type { ScreenHighlighter, ScreenMarkResult } from "./client-tools";
import { AGENT_ATTRIBUTES, labelOfElement, type ScreenReferenceRegistry } from "./DomScreenReader";

/** How long a mark stays. */
export const MARK_VISIBLE_MS = 8000;

/** The most characters of a note. */
const MAX_NOTE_CHARS = 120;

export interface DomScreenHighlighterOptions {
  registry: ScreenReferenceRegistry;
  /** The sentence the live region reads, e.g. "The assistant marked: End date" (translated by the application) */
  announce: (labels: string[]) => string;
}

/**
 * Marks elements with a calm outline (the theme's `--ring`) in an overlay above the page, a short note beside the
 * first one, and one sentence in a polite live region. The overlay never takes a click (`pointer-events: none`) and is
 * never read (`data-agent-skip`). Marks follow scrolling and leave after {@link MARK_VISIBLE_MS}, on Escape or on the
 * next mark.
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
    targets[0].scrollIntoView({ block: 'center', behavior: 'smooth' });
    this.draw(targets, note ? note.slice(0, MAX_NOTE_CHARS) : null);
    this.announce(this.options.announce(Object.values(result.marked)));
    return result;
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
        const below = rect.bottom + 10 + 60 < window.innerHeight;
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
