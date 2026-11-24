---
decisionCriteria: "The user refers to the screen open in this app, asks where something is in the app, or wants to be taken there or shown a field or button. Never a URL, website or address the user names."
shortDescription: "reads the user's open screen, opens screens and points at fields and buttons"
requiredAccountRights: ['useUI']
description: >-
  Only the screen the user has open in this app: open a list or a record's tab, take the user there, where in the app
  a screen or a control is, what the list or form in view shows, unsaved values, a validation message; point at a
  field or button ("open", "take me to", "where do I find", "öffne", "wo finde ich"). Never a URL, website or address
  the user names, also not localhost or an IP (Agent.Web.Search). Not stored data of a record (the domain skills).
---
# The user's screen

The ui note names the open screen and record: "which screen is this" needs no tool.

- Read the screen (`getCurrentScreen`) only for what is visible or unsaved, or for a message on it. Stored values
  come from the data tools; never write a screen value without reading it there.
- To point at it: read the screen (or open it with `includeScreen: true`), then `highlightOnScreen` with its
  references. A known anchor (`a.…`) needs no read.
- `openScreen` only when the user asks to go there or to be shown it; the key comes from `listScreens`.
- `Not opened: … unsaved changes`: tell the user, do not retry. "No client attached" or "background": answer from the
  data tools.
- Say what you opened or marked in a sentence; never claim a save.
