# File sources in the drive tools

**Reader:** host developer

Contents: the find/view split · how an argument becomes a source file · `AI.fileSources` · `viewImages` and the picture tiers.

Since ddd-agents 0.7.0 (needs mgamadeus/ddd ≥ 3.48.0). How a file source of the application (Core port `FileSource`,
skill `ddd-files-specialist`) reaches the agent, and what a host configures.

## The split: find per source, everything else generic

| Job | Who does it |
|---|---|
| **Find** files (search, list, filter by the record they belong to) | one tool per source, written by the module or host that owns the source (a media library's search tool, a ticket's attachment list). It answers references `<source>/<id>`. |
| **Show** a file card | `showDriveFiles` (generic) |
| **Read** a document | `readDriveDocument`, `viewDriveDocumentPages` (generic) |
| **Look at** a picture | `viewImages` (generic) |
| **Copy** into a drive | `saveDriveFile` with the reference as source (generic; the target drive store imports) |

A source has no folders: `listDriveFiles` on a reference is refused, and the refusal names the source's find tool from
the config below. A tool that finds files of a source therefore returns references, never bytes, URLs or pictures of
its own: the generic tools do the rest under the same rights and flow rule.

## How an argument becomes a source file

`AgentDriveStoresService::resolveDrivePath()` checks a file argument with `findFileSourceReference()`: only the exact
form `<source>/<id>` of a registered source other than `drive` counts. `media/901` is the file 901 of the source
`media`; `/workspace/media/901`, `./media/901` and a folder of the own drive named `media` stay paths. Such an argument
resolves to the read-only mount of the adapter store `files` (`FileSourcesAgentDriveStoreService`, an
`AgentDriveStore` and `AgentDriveFileContentReader` over `FileSourcesService`): no mounts, no folders, nothing saved
INTO a source.

`saveDriveFile` with a reference as source hands it to the target's drive store, which copies with its own import
(ddd-clouddrive ≥ 0.5.0: its import service, under the application's flow rule). The owner's consent
of a `WRITE_WITH_CONSENT` mount counts as the consent the flow rule may ask for.

`agent.drive` is offered as soon as a drive store OR a file source is installed (`AgentDriveStoresService::hasFileSources()`).

## Configuration: `config/app/AI/fileSources.php` (`AI.fileSources`, `AgentFileSourceSettings`)

```php
return [
    // source name => the tool that finds its files; named when the model tries to list a source
    'findTools' => [
        'media' => 'searchMediaLibrary',
        'support' => 'listTicketAttachments',
    ],
    'viewImages' => [
        'maxPerCall' => 4,                 // at most the executor's image budget of 4
        'maxSourceBytes' => 20_971_520,    // largest source picture read
        'maxSourcePixels' => 50_000_000,   // largest source picture decoded
    ],
];
```

An application replaces the file whole; a missing key keeps its default.

## `viewImages` and `AgentFilePicturesService`

`viewImages(files[], size)` (`files`: up to `maxPerCall` references or drive paths; `size` SMALL 512, MEDIUM 1024,
LARGE 2048 pixels on the long edge) answers one line per picture and the pictures themselves. For a reference,
`AgentFilePicturesService::renderPictureForModel()`:

1. reads the facts under the caller's rights (`requireFileFacts`, 404 for an unreadable file);
2. asks the flow rule with `FileFlowTarget::forModel()` BEFORE any byte is read (a refusal is a 403 with the rule's
   sentence);
3. refuses a file that is no picture (and names `readDriveDocument`);
4. reads the bytes within `maxSourceBytes`, renders through `FileSourcesService::renderImagePreviewOfContent()`
   (upright, without metadata, capped by `maxSourcePixels`);
5. keeps the picture in the tool picture store under the store `files` with the id
   `<source>/<id>:<edge>:<fingerprint>` — the fingerprint is the first 12 hex digits of the sha256 of the SOURCE bytes.

The conversation row holds only that reference. On a later turn (`resolvePicture()`), rights and the flow rule are
asked again first (the tiers are keyed by bytes and know no caller), then the fingerprint is compared with the file's
sha256: a changed file has another fingerprint and its old picture is dropped, never shown as if it were current.

Tiers of the tool picture store (`AgentToolCallImageStoreService::writeSourcePicture()` / `readSourcePicture()`):
process cache, shared cache with its TTL, object store under `sources/<store>/<sha256>`. When the flow decision says
`isDurableCopyAllowed = false` (Core's default for a PERSONAL file), the picture stops at the shared cache and never
reaches the object store; after the TTL it is rendered again from the source, under the rights of the running
account. The tiers and the store are configured in `AI.toolCallImageStore` (context skill).

A host tool that showed pictures of its own store through an own `ToolCallImageStore` can drop tool and store: make the
store a `FileSource`, name its find tool in `findTools`, and bind `agent.drive`.
