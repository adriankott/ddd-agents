import { useEffect, useState } from "react";

/**
 * The size of a picture a widget asks for: `THUMBNAIL` for a tile (the host renders at most 320 px), `PREVIEW` for the
 * lightbox (at most 2048 px). The variants of Core's file route (`FileSourcesControllerTrait`).
 */
export type FilePreviewVariant = "THUMBNAIL" | "PREVIEW";

/**
 * Where the widgets that show files by reference (`ImageGallery`, `AttachedFiles`) read their pictures: a URL the
 * browser may put into an `<img>` for one file reference and size, under the viewer's rights. The one seam between
 * those widgets and the host's backend; the host registers it with {@link registerFilePreviewSource} before the first
 * chat. Typical: fetch the host's file route with the session and hand out an object URL of the answer (no public,
 * presigned or object store URL ever reaches the block).
 */
export interface FilePreviewSource {
  /**
   * The URL of the picture; rejects when the viewer may not read the file, it is no picture, or the read failed. The
   * source may cache the answer per reference and variant; a widget asks again whenever it mounts.
   */
  readPictureUrl: (reference: string, variant: FilePreviewVariant, signal: AbortSignal) => Promise<string>;
}

/** The error of a picture read while no source is registered: the tile says the files are not connected. */
export const FILE_PREVIEW_SOURCE_MISSING = "FILE_PREVIEW_SOURCE_MISSING";

let registeredFilePreviewSource: FilePreviewSource | null = null;

/** Registers the source of the pictures. Call it once, outside React, before the first chat; a second call replaces the first. */
export const registerFilePreviewSource = (source: FilePreviewSource): void => {
  registeredFilePreviewSource = source;
};

/** The registered source, or `null` while none is connected. */
export const readFilePreviewSource = (): FilePreviewSource | null => registeredFilePreviewSource;

/** Takes the source off again (tests, a host that disconnects). */
export const unregisterFilePreviewSource = (): void => {
  registeredFilePreviewSource = null;
};

export interface FilePictureState {
  url: string | null;
  status: "loading" | "loaded" | "failed" | "notConnected";
}

/** The picture of one file reference in one size, read through the registered source; `null` reference reads nothing. */
export const useFilePictureUrl = (reference: string | null, variant: FilePreviewVariant): FilePictureState => {
  const [state, setState] = useState<FilePictureState>({ url: null, status: "loading" });
  useEffect(() => {
    if (reference === null) return undefined;
    const source = readFilePreviewSource();
    if (source === null) {
      setState({ url: null, status: "notConnected" });
      return undefined;
    }
    const abortController = new AbortController();
    setState({ url: null, status: "loading" });
    source
      .readPictureUrl(reference, variant, abortController.signal)
      .then((url) => {
        if (!abortController.signal.aborted) setState({ url, status: "loaded" });
      })
      .catch(() => {
        if (!abortController.signal.aborted) setState({ url: null, status: "failed" });
      });
    return () => abortController.abort();
  }, [reference, variant]);
  return reference === null ? { url: null, status: "failed" } : state;
};
