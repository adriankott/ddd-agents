---
decisionCriteria: "A placeholder line `[image <id>, … — no longer attached …]` stands where a picture of an earlier tool result was, and you must look at that picture again."
shortDescription: "see again a picture of an earlier tool result that is no longer attached"
description: >-
  Pictures of earlier tool results (screenshots, workspace images) leave the context at a turn change and leave a
  placeholder line with an image id; this skill attaches one of them again.
---
# Earlier tool pictures

**Scope.** Older tool results keep their text, but their pictures leave the context at a turn change; each one leaves
a line `[image <id>, WxH — no longer attached; to see it again call viewEarlierToolImage with imageId "<id>" …]`.
`viewEarlierToolImage` with that id attaches the same picture again, from the picture store — it never runs the
original tool again, so it shows the state at the time of that call, not the current one.

**When.** Only when you must LOOK at that picture again (compare it, read a detail the text of the result does not
hold). The current turn's pictures are still attached; answer from the result's text where it suffices.

**When it fails.** "no longer stored": the store has dropped the picture — call the tool that produced it again (the
answer names it) if you need the current state. "No earlier tool result … holds the image": the id is wrong; copy it
from the placeholder line.
