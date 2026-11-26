import type { ConversationComposerWidget } from "../../renderer/conversation-composer-widget";

/**
 * The `AttachedFiles` fence (`AttachedFilesConversationWidget.php`): files the owner attached to a message, named by
 * reference (`drive/812`), with the name the host stored them under and whether each is a picture. The owner's message
 * carries it; the agent reads the references and works with them like any other file.
 */
export interface AttachedFile {
  /** `<source>/<id>`, never a URL */
  reference: string;
  name: string;
  /** A picture: the chip shows its thumbnail (`FilePreviewSource`) */
  isImage: boolean;
}

export const ATTACHED_FILES_WIDGET_TYPE = "AttachedFiles";

/** The most files one message carries. */
export const ATTACHED_FILES_MAX = 12;

const FILE_REFERENCE_PATTERN = /^[a-z][a-z0-9-]{0,31}\/[A-Za-z0-9_-]{1,128}$/;

/** Reads the files of a payload or of composer fields; a file without a valid reference is left out. */
export const readAttachedFiles = (fields: unknown): AttachedFile[] => {
  const rawFiles = fields && typeof fields === "object" ? (fields as { files?: unknown }).files : undefined;
  if (!Array.isArray(rawFiles)) return [];
  const attachedFiles: AttachedFile[] = [];
  for (const rawFile of rawFiles) {
    if (attachedFiles.length >= ATTACHED_FILES_MAX || !rawFile || typeof rawFile !== "object") continue;
    const { reference, name, isImage } = rawFile as Record<string, unknown>;
    if (typeof reference !== "string" || !FILE_REFERENCE_PATTERN.test(reference)) continue;
    attachedFiles.push({
      reference,
      name: typeof name === "string" && name.trim() !== "" ? name.trim() : reference,
      isImage: isImage === true,
    });
  }
  return attachedFiles;
};

export const parseAttachedFilesPayload = (raw: string): AttachedFile[] => {
  try {
    return readAttachedFiles(JSON.parse(raw));
  } catch {
    return [];
  }
};

/**
 * The composer widget a host holds for files it uploaded (`AgentChat`'s `hostComposerWidgets`): sent as an
 * `AttachedFiles` fence with the owner's next message. The host clears it in `onComposerWidgetsSent`.
 */
export const buildAttachedFilesComposerWidget = (
  attachedFiles: AttachedFile[],
  composerWidgetId: string = ATTACHED_FILES_WIDGET_TYPE,
): ConversationComposerWidget => ({
  composerWidgetId,
  widgetName: ATTACHED_FILES_WIDGET_TYPE,
  fields: { files: attachedFiles.slice(0, ATTACHED_FILES_MAX).map((file) => ({ ...file })) },
});
