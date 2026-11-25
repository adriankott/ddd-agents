import { isCanvasId } from "../../../renderer/canvas/canvas-behaviours";
import { type CanvasAnchor, type CanvasIdentity, messageAnchor } from "../../../renderer/canvas/canvas-state";
import type { DriveFileCard } from "./drive-files";

/**
 * Says which canvas shows a file or folder of one store: the canvas and its instance (`canvasInstanceIdForReference`),
 * or `null` when this entry has none (it then opens in a new tab or only downloads, as without an opener). Pure and
 * cheap: the widget asks on every render of a row.
 */
export type DriveFileCanvasOpener = (card: DriveFileCard) => CanvasIdentity | null;

const registeredOpeners = new Map<string, DriveFileCanvasOpener>();

/**
 * Registers the canvas opener of one drive store (the `store` a `DriveFiles` block names): the entries of that store
 * then open in the canvas beside the chat instead of a new tab. The canvas itself is a registered canvas kind
 * (`registerCanvasKind`, with `openedByWidgets: ["DriveFiles"]`). Call it once per store, outside React, before the
 * first chat; a second call for a store replaces the first. Stores without an opener keep open-in-a-tab and download.
 */
export const registerDriveFileCanvasOpener = (store: string, opener: DriveFileCanvasOpener): void => {
  registeredOpeners.set(store, opener);
};

/** The opener of a store, or `null` when the store registered none. */
export const readDriveFileCanvasOpener = (store: string): DriveFileCanvasOpener | null =>
  registeredOpeners.get(store) ?? null;

/** Takes a store's opener off again (tests, a host that disconnects its store). */
export const unregisterDriveFileCanvasOpener = (store: string): void => {
  registeredOpeners.delete(store);
};

/** The canvas one row of a `DriveFiles` block opens, with its anchor. */
export interface DriveFileCanvasTarget extends CanvasIdentity {
  readonly anchor: CanvasAnchor;
}

/**
 * The canvas a row opens: the store's opener's answer, anchored on the block's message. `null` when the store has no
 * opener, the opener has no canvas for this entry, the canvas it names is not declared (its kind was never registered)
 * or the block does not know its message yet (a reply still streaming) — the row then keeps its former actions.
 */
export const driveFileCanvasTargetOf = (
  store: string,
  card: DriveFileCard,
  messageId: string | number | undefined,
): DriveFileCanvasTarget | null => {
  const opener = readDriveFileCanvasOpener(store);
  if (opener === null) return null;
  const numericMessageId = messageId === undefined ? Number.NaN : Number(messageId);
  if (!Number.isInteger(numericMessageId) || numericMessageId <= 0) return null;
  const identity = opener(card);
  if (identity === null || !isCanvasId(identity.canvasId) || identity.instanceId === "") return null;
  return { canvasId: identity.canvasId, instanceId: identity.instanceId, anchor: messageAnchor(numericMessageId) };
};
