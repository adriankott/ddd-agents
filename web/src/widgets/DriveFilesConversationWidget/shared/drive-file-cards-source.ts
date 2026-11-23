import { registerWidgetHydrator } from "../../../renderer/widget-hydration/widget-hydration.registry";
import {
  buildDriveFilesHydrationKey,
  DRIVE_FILES_WIDGET_TYPE,
  type DriveFileCard,
  type DriveFileContent,
  type DriveFilesRequest,
} from "./drive-files";

/**
 * Where the `DriveFiles` cards read their data: the store's file cards under the viewer's rights and a short-lived URL
 * of a file's content. The one seam between the widget and a store's backend (a cloud drive, a workspace); the host
 * registers one with {@link registerDriveFileCardsSource} before the first chat.
 */
export interface DriveFileCardsSource {
  /** The cards of one block, in the block's order; a reference the viewer may not read is left out. */
  readFileCards: (request: DriveFilesRequest, signal: AbortSignal) => Promise<DriveFileCard[]>;
  /**
   * A URL of the card's content: `inline` to open it in a tab, `attachment` to download it. The widget asks again
   * whenever it needs a URL; a card whose URLs expired (`urlsExpireAt`) makes the widget read the cards again after.
   */
  readFileContent: (card: DriveFileCard, disposition: "inline" | "attachment") => Promise<DriveFileContent>;
  /**
   * The link to a card's place in the host's drive screen when the card carries no `openLink` of its own; `null`
   * (or no builder) shows no such action.
   */
  buildDriveScreenHref?: (card: DriveFileCard) => string | null;
}

/** The hydration error of a card read while no source is registered: the widget shows "not connected". */
export const DRIVE_FILE_CARDS_SOURCE_MISSING = "DRIVE_FILE_CARDS_SOURCE_MISSING";

let registeredSource: DriveFileCardsSource | null = null;

/**
 * Registers the store behind the `DriveFiles` cards. Call it once, outside React, before the first chat; a second call
 * replaces the first.
 */
export const registerDriveFileCardsSource = (source: DriveFileCardsSource): void => {
  registeredSource = source;
};

/** The registered source, or `null` while no store is connected to this frontend. */
export const readDriveFileCardsSource = (): DriveFileCardsSource | null => registeredSource;

/** Takes the source off again (tests, a host that disconnects its store). */
export const unregisterDriveFileCardsSource = (): void => {
  registeredSource = null;
};

/**
 * The package hydrator of the widget: reads through the registered source, fails with
 * {@link DRIVE_FILE_CARDS_SOURCE_MISSING} without one. Registered when the widget registers, so a preview's fixture
 * hydration answers it like any other hydrator.
 */
export const registerDriveFilesHydrator = (): void => {
  registerWidgetHydrator<DriveFilesRequest, DriveFileCard[]>(DRIVE_FILES_WIDGET_TYPE, {
    hydrator: (request, { signal }) => {
      const source = readDriveFileCardsSource();
      if (source === null) return Promise.reject(new Error(DRIVE_FILE_CARDS_SOURCE_MISSING));
      return source.readFileCards(request, signal);
    },
    deriveKey: buildDriveFilesHydrationKey,
    isPayloadEqual: (previous, next) =>
      previous !== undefined &&
      buildDriveFilesHydrationKey(DRIVE_FILES_WIDGET_TYPE, previous) ===
        buildDriveFilesHydrationKey(DRIVE_FILES_WIDGET_TYPE, next),
  });
};
