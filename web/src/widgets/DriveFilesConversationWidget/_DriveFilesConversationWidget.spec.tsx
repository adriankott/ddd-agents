// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type AgentsEnvironment, getAgentsEnvironment, setAgentsEnvironment } from "../../provider/environment";
import { createAgentsWidgetHydrationStateStore } from "../../renderer/widget-hydration/widget-hydration-state-store";
import { DriveFilesConversationWidget } from "./DriveFilesConversationWidget";
import {
  type DriveFileCardsSource,
  registerDriveFileCardsSource,
  registerDriveFilesHydrator,
  unregisterDriveFileCardsSource,
} from "./shared/drive-file-cards-source";
import type { DriveFileCard } from "./shared/drive-files";

const PAYLOAD = JSON.stringify({ store: "drive", references: ["drive/812", "drive/813", "drive/40"], title: "Deck" });

const CARDS: DriveFileCard[] = [
  { reference: "drive/812", name: "deck.pptx", path: "out/deck.pptx", sizeBytes: 2048, urlsExpireAt: "2099-01-01 00:00:00", openLink: "/drive?space=1&file=812" },
  { reference: "drive/813", name: "deck.pdf", path: "out/deck.pdf", contentType: "application/pdf", urlsExpireAt: "2099-01-01 00:00:00" },
  { reference: "drive/40", name: "web", path: "web", isFolder: true, childCount: 3, urlsExpireAt: "2099-01-01 00:00:00", openLink: "https://evil.example/x" },
];

const buildSource = (cards: DriveFileCard[]): DriveFileCardsSource => ({
  readFileCards: vi.fn(() => Promise.resolve(cards)),
  readFileContent: vi.fn((card: DriveFileCard) => Promise.resolve({ url: `https://store.example/${card.reference}` })),
  buildDriveScreenHref: (card) => `/drive?node=${card.reference}`,
});

describe("DriveFilesConversationWidget", () => {
  let environmentBefore: AgentsEnvironment;

  beforeEach(() => {
    environmentBefore = getAgentsEnvironment();
    setAgentsEnvironment({ stateStore: createAgentsWidgetHydrationStateStore() });
    registerDriveFilesHydrator();
  });

  afterEach(() => {
    cleanup();
    unregisterDriveFileCardsSource();
    setAgentsEnvironment(environmentBefore);
  });

  it("says the drive is not connected while no source is registered", async () => {
    render(<DriveFilesConversationWidget payload={PAYLOAD} />);
    await waitFor(() => expect(screen.getByText("The drive is not connected yet")).toBeTruthy());
  });

  it("renders nothing for a block without references", () => {
    const { container } = render(<DriveFilesConversationWidget payload={JSON.stringify({ store: "drive", references: [] })} />);
    expect(container.innerHTML).toBe("");
  });

  it("lists files and folders: the pptx opens its PDF twin, the folder has no open or download", async () => {
    const source = buildSource(CARDS);
    registerDriveFileCardsSource(source);
    render(<DriveFilesConversationWidget payload={PAYLOAD} />);
    await waitFor(() => expect(screen.getAllByTestId("drive-files-widget-card")).toHaveLength(3));
    expect(screen.getByText("Deck")).toBeTruthy();
    expect(screen.getAllByTestId("drive-files-widget-open")).toHaveLength(2);
    expect(screen.getAllByTestId("drive-files-widget-download")).toHaveLength(2);
    // the pptx's own link, the pdf's built link, the folder's foreign link replaced by the built one
    expect(screen.getAllByTestId("drive-files-widget-show-in-drive")).toHaveLength(3);

    const tab = { opener: {}, location: { href: "" }, close: vi.fn() };
    const openSpy = vi.spyOn(window, "open").mockReturnValue(tab as unknown as Window);
    await act(async () => {
      fireEvent.click(screen.getAllByTestId("drive-files-widget-open")[0]);
    });
    await waitFor(() => expect(tab.location.href).toBe("https://store.example/drive/813"));
    expect(source.readFileContent).toHaveBeenCalledWith(expect.objectContaining({ reference: "drive/813" }), "inline");
    openSpy.mockRestore();
  });

  it("shows no file when none is readable", async () => {
    registerDriveFileCardsSource(buildSource([]));
    render(<DriveFilesConversationWidget payload={PAYLOAD} />);
    await waitFor(() => expect(screen.getByText("No file to show")).toBeTruthy());
  });
});
