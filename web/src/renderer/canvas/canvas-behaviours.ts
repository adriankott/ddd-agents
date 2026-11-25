import { getFurtherCanvasBehaviours } from "../../provider/environment";
import { getCanvasKindIds, readCanvasKindDeclaration } from "./canvas-kind-declarations";

/**
 * The CANVAS BEHAVIOURS — one declaration per canvas of how it lives on the surface: how long it stays (`lifetime`),
 * how it takes the screen (`layout`), what happens on a narrow viewport and whether its body starts flush at the top.
 *
 * Keyed on a CANVAS id, not a widget name: one body may have several openers, and one widget can be inline with a
 * detail canvas. The widget registry lists which canvases a widget may open (`opensCanvases`); the canvas BODIES (React
 * components) live in `canvasBodies.tsx`, imported only by the shell. The package declares the canvases of its own
 * widgets here (`packageCanvasBehaviours`); an app declares the canvases of its widgets on its agents environment
 * (`AgentsEnvironment.furtherCanvasBehaviours`), read at every lookup; a further package (a drive, an editor) brings a
 * whole canvas kind — behaviour, body, opener widgets — with `registerCanvasKind` (`canvas-kinds.ts`), whose behaviour
 * is kept in `canvas-kind-declarations.ts`. This module imports nothing but that one and the environment
 * module (which has no runtime imports), so the conversation slice and its specs can read it during store
 * initialisation (the widget registry is downstream of the store; a slice → registry import forms a temporal-dead-zone
 * cycle).
 */

/** The canvases of the package's own widgets. */
export type PackageCanvasId = "conversationSearchResults" | "webSearchResults" | "entityDetail" | "mapFullScreen";

/**
 * The id of a canvas: one of the package's (`PackageCanvasId`), one an app declares
 * (`AgentsEnvironment.furtherCanvasBehaviours`) or one a registered canvas kind brings (`registerCanvasKind`). Append-only while a stored pointer (`canvasStorage.ts`) may still name
 * the id. A string, because the app's ids are not known to the package; `isCanvasId` checks a value against both.
 */
export type CanvasId = PackageCanvasId | (string & {});

/**
 * `turn`: the canvas belongs to one question — the next SEND closes it for good (the card in the transcript is the
 * way back). `pinned`: a work surface for the whole conversation — it stays through sends and stays re-openable after
 * the customer closes it. Lifetime says nothing about a reload: the canvas open at reload time is restored for BOTH.
 */
export type CanvasLifetime = "turn" | "pinned";

/**
 * `split`: the canvas is the right pane beside the chat. `canvasFillsScreen`: the canvas takes the screen and the chat
 * becomes the narrow companion (a full work surface such as an editor); the host's compact mode is overridden while it
 * is open.
 */
export type CanvasLayout = "split" | "canvasFillsScreen";

/**
 * Below the viable split width (`CANVAS_VIABLE_MIN_WIDTH`): `none` — a live delivery does not take the phone screen
 * over (the card stays, the customer taps it), and the wide → narrow crossing closes an open canvas; `overlay` — the
 * canvas is the widget's only surface, so it opens as the full-screen overlay and survives the crossing.
 */
export type CanvasNarrowViewport = "none" | "overlay";

export interface CanvasBehaviour {
  readonly lifetime: CanvasLifetime;
  readonly layout: CanvasLayout;
  /** Width of the canvas pane as a percentage of the split. Only read for `layout: "split"`; default 50. */
  readonly widthPercent?: number;
  readonly narrowViewport: CanvasNarrowViewport;
  /**
   * The body starts flush at its top edge (a hero image, an iframe) — the pane keeps its gap under the close row, unless
   * `overlayCloseButton` floats the row over the body.
   */
  readonly fullBleed: boolean;
  /**
   * The close button floats over the body's top edge instead of reserving a row of its own, and the pane drops its top
   * and side padding — for a body whose top (a hero image) may sit under the button. The button is then muted
   * `inverted` + `onDark`, since it sits on the dark image. Default false: the row stays in flow so it never covers
   * content.
   */
  readonly overlayCloseButton?: boolean;
  /**
   * The body's title sits in the close button's row instead of in a row of its own below it: the body portals it into
   * the pane's title slot (`useCanvasTitleSlot`). Default false: the row holds the button alone.
   */
  readonly titleBesideCloseButton?: boolean;
}

/** Width of the canvas pane (% of the split) when a behaviour declares none. */
export const DEFAULT_CANVAS_WIDTH_PERCENT = 50;

/** The narrowest the customer can pull the canvas (px); pulling further and holding folds the tab into "close". */
export const CANVAS_MIN_WIDTH_PX = 420;

/** What the chat column keeps beside the widest canvas (px). */
export const CHAT_COLUMN_MIN_WIDTH_PX = 360;

/**
 * The boxed canvas card's inset from its pane edge (px) — the pull tab sits on the card's edge, not the pane's. The
 * pane's padding reads it as `--agents-canvas-inset` (`.splitViewPaneBoxed`), so this is the one place it is set.
 */
export const CANVAS_BOXED_INSET_PX = 8;

/** Width of the canvas pane for `layout: "canvasFillsScreen"` — the chat keeps a narrow companion column. */
export const CANVAS_FILLS_SCREEN_WIDTH_PERCENT = 72;

/** The canvases of the package's own widgets. */
export const packageCanvasBehaviours: Readonly<Record<PackageCanvasId, CanvasBehaviour>> = {
  conversationSearchResults: { lifetime: "turn", layout: "split", narrowViewport: "none", fullBleed: false },
  webSearchResults: { lifetime: "turn", layout: "split", narrowViewport: "none", fullBleed: false },
  // One entity's detail, opened from a ComposedView row (`openEntity`) — a click, never a live delivery.
  entityDetail: { lifetime: "turn", layout: "split", widthPercent: 40, narrowViewport: "none", fullBleed: false },
  // A ComposedView `Map` node, full screen — the widest split a turn canvas takes, the map flush.
  mapFullScreen: { lifetime: "turn", layout: "split", widthPercent: 65, narrowViewport: "none", fullBleed: true },
};

const hasOwnCanvasBehaviour = (behaviours: Readonly<Record<string, CanvasBehaviour>>, canvasId: string): boolean =>
  Object.prototype.hasOwnProperty.call(behaviours, canvasId);

/** Whether the id is one of the package's own canvases (a registered kind and an app can never take one). */
export const isPackageCanvasId = (canvasId: string): canvasId is PackageCanvasId =>
  hasOwnCanvasBehaviour(packageCanvasBehaviours, canvasId);

/**
 * The names of the widgets a registered canvas kind opens from (`CanvasKind.openedByWidgets`): a stored pointer of the
 * kind restores only while its anchor message still carries one of them. Empty for another canvas (its openers are the
 * registry's `opensCanvases`).
 */
export const canvasKindOpenerWidgetNamesOf = (canvasId: CanvasId): readonly string[] =>
  readCanvasKindDeclaration(canvasId)?.openedByWidgets ?? [];

/**
 * The behaviour of a canvas: the package's own, else the one the app declares
 * (`AgentsEnvironment.furtherCanvasBehaviours`), else the one of a registered canvas kind (`registerCanvasKind`);
 * undefined for an id nobody declared (a stale stored pointer, a typo — the opening rule ignores it). Nobody can replace
 * a package canvas's behaviour; the app's declaration wins over a registered kind's, so a host can tune a kind a
 * further package brings.
 */
export const canvasBehaviourOf = (canvasId: CanvasId): CanvasBehaviour | undefined => {
  if (isPackageCanvasId(canvasId)) {
    return packageCanvasBehaviours[canvasId];
  }
  const furtherCanvasBehaviours = getFurtherCanvasBehaviours();
  if (hasOwnCanvasBehaviour(furtherCanvasBehaviours, canvasId)) return furtherCanvasBehaviours[canvasId];
  return readCanvasKindDeclaration(canvasId)?.behaviour;
};

/** Every declared canvas id: the package's own first, then the app's further ones, then the registered kinds'. */
export const getDeclaredCanvasIds = (): readonly CanvasId[] => {
  const packageCanvasIds = Object.keys(packageCanvasBehaviours);
  const furtherCanvasIds = Object.keys(getFurtherCanvasBehaviours()).filter(
    (canvasId) => !packageCanvasIds.includes(canvasId),
  );
  const kindCanvasIds = getCanvasKindIds().filter(
    (canvasId) => !packageCanvasIds.includes(canvasId) && !furtherCanvasIds.includes(canvasId),
  );
  return [...packageCanvasIds, ...furtherCanvasIds, ...kindCanvasIds];
};

/** Type guard for a value read from untrusted input (`sessionStorage`, a URL): is it a declared canvas id? */
export const isCanvasId = (value: unknown): value is CanvasId =>
  typeof value === "string" && canvasBehaviourOf(value) !== undefined;

export const canvasWidthPercentOf = (behaviour: CanvasBehaviour): number =>
  behaviour.layout === "canvasFillsScreen"
    ? CANVAS_FILLS_SCREEN_WIDTH_PERCENT
    : (behaviour.widthPercent ?? DEFAULT_CANVAS_WIDTH_PERCENT);
