import type React from "react";
import { type CanvasBehaviour, type CanvasId, isPackageCanvasId } from "./canvas-behaviours";
import { declareCanvasKind, forgetCanvasKind } from "./canvas-kind-declarations";
import type { CanvasBodyProps } from "./canvas-body-props";
import { canvasFeatureFlags, registerCanvasFeatureFlags } from "./canvas-feature-flags";
import { canvasBodies, registerCanvasBodies } from "./canvasBodies";

/**
 * A CANVAS KIND — everything one canvas is made of, in one declaration: its id, how it lives on the surface
 * (`behaviour`), the component the pane mounts (`body`) and the widgets it opens from. The way a further package (a
 * drive's file viewer, an editor) brings its canvas to the chat without the app declaring anything: the package calls
 * `registerCanvasKind` once, outside React, before the first render.
 *
 * The app's own canvases keep their two declarations (`AgentsEnvironment.furtherCanvasBehaviours` +
 * `registerCanvasBodies`); both ways end in the same lookups (`canvasBehaviourOf`, `canvasBodies`), and the app's
 * behaviour for an id wins over a kind's.
 */
export interface CanvasKind {
  /**
   * The id of the canvas. Append-only while a stored pointer may still name it; never one of the package's own
   * (`PackageCanvasId`). Prefix it with the package's topic (`driveFile`), so two packages never meet on one id.
   */
  readonly canvasId: CanvasId;
  readonly behaviour: CanvasBehaviour;
  /**
   * The body the pane mounts. It receives the pointer only (`CanvasBodyProps`): what it shows it reads itself from the
   * instance id (`canvasInstanceIdForReference` → `referenceOfCanvasInstanceId`) or from the anchor message.
   */
  readonly body: React.ComponentType<CanvasBodyProps>;
  /**
   * The names of the widgets in a message this canvas opens from (`DriveFiles`). After a reload the pointer of the
   * canvas restores only while its anchor message still carries one of them; without a name the canvas never restores.
   */
  readonly openedByWidgets?: readonly string[];
  /** The requirement (an app feature flag) the body is gated on (`registerCanvasFeatureFlags`); default ungated. */
  readonly featureFlag?: string;
}

/**
 * Registers a canvas kind: its behaviour, its body, its opener widgets and its gate. Runs outside React, before the
 * first render; a second registration of an id replaces the first (a hot reload registers again).
 *
 * @throws Error for an id of the package's own canvases
 */
export const registerCanvasKind = (kind: CanvasKind): void => {
  if (isPackageCanvasId(kind.canvasId)) {
    throw new Error(
      `The canvas id "${kind.canvasId}" is one of the package's own canvases; a canvas kind needs its own id.`,
    );
  }
  declareCanvasKind(kind.canvasId, { behaviour: kind.behaviour, openedByWidgets: kind.openedByWidgets ?? [] });
  registerCanvasBodies({ [kind.canvasId]: kind.body });
  if (kind.featureFlag !== undefined) registerCanvasFeatureFlags({ [kind.canvasId]: kind.featureFlag });
  else delete canvasFeatureFlags[kind.canvasId];
};

/** Takes a registered canvas kind off again (tests, a host that disconnects a package). */
export const unregisterCanvasKind = (canvasId: CanvasId): void => {
  if (isPackageCanvasId(canvasId)) return;
  forgetCanvasKind(canvasId);
  delete canvasBodies[canvasId];
  delete canvasFeatureFlags[canvasId];
};
