---
name: DriveFiles
emitter: tool
summary: Files of a drive store as cards in the chat — name, type, size, date, a thumbnail; open, download and a link to the file's place in the drive screen for people with the right.
description: The file cards the tool showDriveFiles writes. The block names a drive store and file references only (drive/812, at most 20) and an optional headline; the component reads the cards through the host's DriveFileCardsSource under the viewer's rights, so a block never carries a URL and a viewer without the right sees no file. Per file one row with a thumbnail or a type tile, the name, type · size · date (a folder shows its entries), a state chip when the store reports one, and the actions — open in a new tab (an office file opens its PDF twin of the same name, without one it only downloads), download, and "show in the drive" through the card's openLink or the host's href builder. Expired card URLs are read again. Without a registered source the card says the drive is not connected.
---

# DriveFilesConversationWidget

## Description
Files the agent made or found, as cards the owner opens and downloads. The block is written by the tool
`showDriveFiles` (backend `DriveFilesConversationWidget`); the component reads the cards through the
`DriveFileCardsSource` the host registers (`registerDriveFileCardsSource()`), under the viewer's rights. A reference the
viewer may not read is left out by the store; none readable shows "No file to show" (render report `empty`).

Per row: a thumbnail (images; a failed thumbnail falls back) or a tile with the file type, a folder tile for folders,
the name, type · size · date (a folder: its number of entries), the store's state as a chip (still being checked,
blocked …) and the actions:
- **Open** in a new tab — files a browser shows itself (PDF, images, video, text). An office file (pptx, docx, xlsx …)
  opens its PDF twin from the same block (same folder, same name, `.pdf`); without one it only downloads.
- **Download** — the store's attachment URL.
- **Show in the drive** — the card's `openLink` when it is a path of this app or a same-origin URL, else the source's
  `buildDriveScreenHref(card)`; hidden when neither gives a link. Opened through the host's `onNavigate`.

Card URLs are short-lived: the widget asks the source for a URL at every click and reads the block's cards again when
they had expired (`urlsExpireAt`), so thumbnails renew too.

States: loading (skeleton with the headline), failed (retry), not connected (no source registered), empty, the list.

## Data structure — SELF-HYDRATING (store + references only)
```ts
interface DriveFilesPayload {
  store: string;          // the drive store's name — "drive"
  references: string[];   // file references, e.g. "drive/812", at most 20, in the order to show
  title?: string;         // a headline in the owner's language, at most 160 characters; OMIT → "N files from the drive"
  agentSpaceId?: number;  // the space the card was written in (the host's self-hydration key field)
}
```

```widget
{ "type": "DriveFiles", "store": "drive", "references": ["drive/812", "drive/813"], "title": "Kampagnen-Performance 2026" }
```

## When to use
- Written by the tool `showDriveFiles` — the owner should open or download files you made or found. Call the tool with
  their paths; never paste a link or the file's content instead.
- Hand a deck over as pptx and pdf in one card: the pptx then opens as the PDF.

## Host setup
```ts
import { registerDriveFileCardsSource } from "@mgamadeus/ddd-agents";

registerDriveFileCardsSource({
  readFileCards: (request, signal) => …,          // the store's cards for the block (viewer's rights)
  readFileContent: (card, disposition) => …,      // { url, fileName? } — inline to open, attachment to download
  buildDriveScreenHref: (card) => …,              // optional: the drive screen link when the card has no openLink
});
```
A cloud drive host registers the source of `@mgamadeus/ddd-clouddrive/agents`.

A store that registered a canvas opener (`registerDriveFileCanvasOpener(store, opener)` with a canvas kind of
`registerCanvasKind`) opens its entries in the canvas beside the chat; without one a file opens in a new tab.
