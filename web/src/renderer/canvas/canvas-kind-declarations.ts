import type { CanvasBehaviour } from "./canvas-behaviours";

/**
 * What a registered canvas kind declares besides its body: where `registerCanvasKind` (`canvas-kinds.ts`) keeps the
 * behaviour and the opener widgets, and where the lookups of `canvas-behaviours.ts` read them. A module without a
 * runtime import, so the conversation slice can reach it during store initialisation (the bodies stay with the shell).
 */
export interface CanvasKindDeclaration {
  readonly behaviour: CanvasBehaviour;
  readonly openedByWidgets: readonly string[];
}

/** The registered canvas kinds by canvas id. Filled before the first render, read at every lookup. */
const canvasKindDeclarations = new Map<string, CanvasKindDeclaration>();

/** Keeps a kind's declaration; a second one for an id replaces the first. Called by `registerCanvasKind` only. */
export const declareCanvasKind = (canvasId: string, declaration: CanvasKindDeclaration): void => {
  canvasKindDeclarations.set(canvasId, {
    behaviour: declaration.behaviour,
    openedByWidgets: [...declaration.openedByWidgets],
  });
};

/** Takes a kind's declaration off again. Called by `unregisterCanvasKind` only. */
export const forgetCanvasKind = (canvasId: string): void => {
  canvasKindDeclarations.delete(canvasId);
};

export const readCanvasKindDeclaration = (canvasId: string): CanvasKindDeclaration | undefined =>
  canvasKindDeclarations.get(canvasId);

/** The ids of the registered canvas kinds, in the order they were registered. */
export const getCanvasKindIds = (): string[] => [...canvasKindDeclarations.keys()];
