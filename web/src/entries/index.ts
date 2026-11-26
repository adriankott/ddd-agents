// `@mgamadeus/ddd-agents` — the provider (environment, replaceable components, texts) and every registration function
// an app calls once before the first render.
// The package's global stylesheet (package variables with their defaults, the default components' look) joins the one
// `styles.css` the build writes; the module stylesheets join it through the components that import them.
import "../styles/agents.scss";
export * from "../provider";
export {
  registerConversationWidgets,
  type ConversationWidgetEntry,
} from "../renderer/conversationWidgetRegistry";
export { registerCanvasBodies } from "../renderer/canvas/canvasBodies";
// A whole canvas of a further package (behaviour, body, opener widgets) in one registration.
export { registerCanvasKind, unregisterCanvasKind, type CanvasKind } from "../renderer/canvas/canvas-kinds";
export { registerCanvasFeatureFlags } from "../renderer/canvas/canvas-feature-flags";
export { registerWidgetHydrator } from "../renderer/widget-hydration/widget-hydration.registry";
// The store behind the DriveFiles cards (a cloud drive, a workspace): one source per host, before the first chat.
export {
  registerDriveFileCardsSource,
  type DriveFileCardsSource,
} from "../widgets/DriveFilesConversationWidget/shared/drive-file-cards-source";
export type { DriveFileCard, DriveFileContent, DriveFilesRequest } from "../widgets/DriveFilesConversationWidget/shared/drive-files";
// The pictures of files by reference (`ImageGallery`, `AttachedFiles`): the host's file route under the viewer's rights.
export {
  registerFilePreviewSource,
  type FilePreviewSource,
  type FilePreviewVariant,
} from "../widgets/_shared/file-preview-source";
// The canvas a store's files and folders open in (a file viewer, a folder browser); stores without one keep the tab.
export {
  registerDriveFileCanvasOpener,
  type DriveFileCanvasOpener,
} from "../widgets/DriveFilesConversationWidget/shared/drive-file-canvas-openers";
export {
  registerComposedViewDataSourceFetcher,
  type ComposedViewDataSourceFetcher,
} from "../renderer/widget-tree/composed-view-data-sources";
// Each registration with what it takes: the definition type and the reader of a folder's modules (an app's glob).
export {
  getEntityDetailBody,
  readEntityDetailBodyDefinitions,
  registerEntityDetailBodies,
  type EntityDetailBodyDefinition,
} from "../kit/entity-detail/entity-detail-bodies";
export {
  readConceptExplainerTypeDefinitions,
  registerConceptExplainerTypes,
} from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-types";
export type { ConceptExplainerTypeDefinition } from "../widgets/ConceptExplainerConversationWidget/shared/concept-explainer-type";
