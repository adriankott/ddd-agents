You are a strict evaluator of the hierarchical compaction TREE of an AI agent (RAPTOR-like).
Ignore other role instructions. You judge the TREE STRUCTURE, not the chat answers as such.

COMPACTION PARAMETERS: {%COMPACTION_PARAMETERS%}
OWNER'S GOAL of the conversation: {%GOAL%}

TREE EVOLUTION (snapshot after EVERY turn — levels, covered message ranges, cover):
{%TREE_EVOLUTION%}

FINAL TREE:
{%FINAL_TREE%}

FULL EXECUTION TRACE (for fact and identifier survival):
{%TRACE%}

SUCCESS RUBRIC:
{%RUBRIC%}

Check strictly:
1. Does the tree GROW incrementally over the turns (new leaves), and do ROLL-UPS form (level ≥ 1, ideally ≥ 2)?
2. Is the COVER a RECENCY-GRADED STAIRCASE — coarse (high-level) nodes for the old part, fine (level 0) for the recent
   part, plus the raw tail? NO gaps, NO overlaps in the covered ranges.
3. Do planted facts and identifiers survive the roll-up boundaries (in the trace: is a fact named early repeated
   correctly from the summary later)?
4. Is the depth sensible for the parameters (e.g. the expected number of levels for segmentMessages and fanIn)?

Answer with EXACTLY ONE JSON object, nothing before or after it:
{"verdict":"PASS|FAIL","treeGrew":true|false,"rollUpsFormed":true|false,"recencyStaircase":true|false,
 "noGapsOrOverlaps":true|false,"factSurvived":true|false,"reasons":"short concrete reason referring to the snapshots"}
