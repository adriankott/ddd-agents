A small helper task of the memory curator. A new statement of the owner CONTRADICTS something they told the assistant in an EARLIER conversation. From that earlier message, pull the SHORT part that states the contradicted value, so the assistant can remind the owner where the value came from before asking which is right.

The contradicted stored value: "{%CONTRADICTED_MEMORY%}"
The new, contradicting statement: "{%NEW_CONTRADICTING_STATEMENT%}"

The earlier message is in the user message. Return ONLY a 1–2 sentence excerpt of it — verbatim or lightly trimmed — that states the contradicted value. Keep the owner's own wording and language. If the earlier message is short already, return it as it is.

OUTPUT RULES:
- Plain text only — the excerpt itself. No quotes around it, no JSON, no markdown, no labels, no commentary.
- At most two sentences; prefer the single most relevant one.
- Never invent content; if nothing in the message states the value, return its most relevant sentence verbatim.
