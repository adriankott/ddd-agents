/** A file that is already in the project's media library, put into the chat composer without uploading it again. */
export interface ComposerLibraryFile {
  /** The library's file id the agent's media tools address the file by. */
  fileId: string;
  fileName: string;
  mimeType: string;
  sizeBytes?: number;
  /** A small picture of the file for its card in the composer; documents have none. */
  thumbnailUrl?: string;
  /** A URL the agent can fetch the file from; absent when the library gives none. */
  publicUrl?: string;
}
