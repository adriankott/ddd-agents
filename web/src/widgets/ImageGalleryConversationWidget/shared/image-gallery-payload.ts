/**
 * The `ImageGallery` fence payload (`ImageGalleryConversationWidget.php`): pictures of files, named by file reference
 * (`media/901`, `drive/812`), never by URL. The component loads each picture through the host's
 * {@link FilePreviewSource} under the viewer's rights.
 */
export type ImageGallerySelection = "none" | "one" | "many";

export type ImageGalleryDisplay = "inline" | "canvas";

export interface ImageGalleryFile {
  /** `<source>/<id>`: the file, never a URL */
  reference: string;
  /** The letter of the tile, by position: A for the first */
  letter: string;
  label?: string;
  caption?: string;
}

export interface ImageGalleryPayload {
  title?: string;
  /** What the gallery is for (`pick-REWARD_PHOTO-8841`), named in every reaction sentence */
  name?: string;
  selection: ImageGallerySelection;
  display?: ImageGalleryDisplay;
  files: ImageGalleryFile[];
}

export const IMAGE_GALLERY_WIDGET_TYPE = "ImageGallery";

/** The most pictures of one gallery: the letters A to L. */
export const IMAGE_GALLERY_MAX_FILES = 12;

export const IMAGE_GALLERY_LETTERS = "ABCDEFGHIJKL";

/** `<source>/<id>` as Core's `FileReference` accepts it. */
const FILE_REFERENCE_PATTERN = /^[a-z][a-z0-9-]{0,31}\/[A-Za-z0-9_-]{1,128}$/;

/** A gallery name as the reader matches it after the wire name. */
const GALLERY_NAME_PATTERN = /^[A-Za-z0-9_.:-]{1,80}$/;

const readText = (value: unknown): string | undefined =>
  typeof value === "string" && value.trim() !== "" ? value.trim() : undefined;

/**
 * Reads the payload; a file without a valid reference is left out, a thirteenth one too. Null when no picture is
 * left (the widget then renders nothing and reports the drop).
 */
export const parseImageGalleryPayload = (raw: string): ImageGalleryPayload | null => {
  let parsed: unknown;
  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }
  if (!parsed || typeof parsed !== "object") return null;
  const fields = parsed as Record<string, unknown>;
  const rawFiles = Array.isArray(fields.files) ? fields.files : [];
  const files: ImageGalleryFile[] = [];
  const seenReferences = new Set<string>();
  for (const rawFile of rawFiles) {
    if (files.length >= IMAGE_GALLERY_MAX_FILES) break;
    const fileFields = rawFile && typeof rawFile === "object" ? (rawFile as Record<string, unknown>) : null;
    const reference = typeof rawFile === "string" ? rawFile.trim() : readText(fileFields?.reference);
    if (reference === undefined || !FILE_REFERENCE_PATTERN.test(reference) || seenReferences.has(reference)) continue;
    seenReferences.add(reference);
    files.push({
      reference,
      letter: IMAGE_GALLERY_LETTERS[files.length],
      label: readText(fileFields?.label),
      caption: readText(fileFields?.caption),
    });
  }
  if (files.length === 0) return null;
  const selection: ImageGallerySelection =
    fields.selection === "one" || fields.selection === "many" ? fields.selection : "none";
  const display: ImageGalleryDisplay | undefined =
    fields.display === "inline" || fields.display === "canvas" ? fields.display : undefined;
  const name = readText(fields.name);
  return {
    title: readText(fields.title),
    name: name !== undefined && GALLERY_NAME_PATTERN.test(name) ? name : undefined,
    selection,
    display,
    files,
  };
};

/** The sentence start that names the gallery: the wire name and its name when it has one. */
export const buildImageGallerySentencePrefix = (name: string | undefined): string =>
  name ? `${IMAGE_GALLERY_WIDGET_TYPE} ${name}` : IMAGE_GALLERY_WIDGET_TYPE;

/**
 * The pick the widget posts (FRONTEND_WIDGET_CLICK): `ImageGallery: the operator picked A (media/901)`, several
 * `ImageGallery: the operator picked 2 pictures: A (media/901), C (media/903)`, in letter order. A stored contract
 * (`image-gallery-widget-contract.json`), read by `ImageGalleryConversationWidget::readAnswer()`.
 */
export const buildImageGalleryPickSentence = (name: string | undefined, pickedFiles: ImageGalleryFile[]): string => {
  const parts = [...pickedFiles]
    .sort((first, second) => first.letter.localeCompare(second.letter))
    .map((file) => `${file.letter} (${file.reference})`);
  const picked = parts.length === 1 ? parts[0] : `${parts.length} pictures: ${parts.join(", ")}`;
  return `${buildImageGallerySentencePrefix(name)}: the operator picked ${picked}`;
};

/** The decline the widget posts: `ImageGallery: the operator declined all pictures`. */
export const buildImageGalleryDeclineSentence = (name: string | undefined): string =>
  `${buildImageGallerySentencePrefix(name)}: the operator declined all pictures`;
