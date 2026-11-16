import type { ComposedViewComponentDefinition } from "../../../composed-view-component-types";
import { VideoWidgetNode } from "./VideoWidget.composed";

/**
 * The node type `VideoCard`, the name of `VideoWidget` before 0.1.0-alpha.59: stored ComposedView trees that still
 * carry it render as a `VideoWidget`.
 */
export const composedViewComponent: ComposedViewComponentDefinition = {
  nodeType: "VideoCard",
  component: VideoWidgetNode,
};
