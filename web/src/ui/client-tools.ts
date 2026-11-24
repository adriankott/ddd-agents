/**
 * The client half of the agent's client tools: a tool on the
 * server sends a request to the client (a browser tab, an app) that shows the conversation; the client executes it through the
 * application's adapters and posts the answer. Everything here is generic: no menu, no route, no word of one
 * application. The application supplies the four adapters (`ScreenAdapters`).
 *
 * Pure (no React, no app imports), so the logic tests cover it.
 */

/** What the server asks a tab to do (`AgentClientToolRequest::TYPE_*`). */
export type ClientToolRequestType = 'READ_SCREEN' | 'LIST_TARGETS' | 'OPEN_TARGET' | 'HIGHLIGHT';

/** A request as the run-state read delivers it. */
export interface ClientToolRequestDelivery {
  id: number;
  requestType: ClientToolRequestType | string;
  argumentsJson: string;
  secondsLeft: number;
}

/** The answer a tab posts: `OK` or a refusal code, the text, small facts. */
export interface ClientToolAnswer {
  outcome: string;
  text: string;
  details: Record<string, string | number | boolean | null>;
}

/** A tab never starts a request with fewer seconds left: it would finish after the tool gave up. */
export const MIN_SECONDS_LEFT_TO_START = 3;

/** The most characters of an answer text (the server refuses more). */
export const MAX_ANSWER_TEXT_CHARS = 24000;

/** The refusal codes a tab answers with. */
export const CLIENT_TOOL_OUTCOMES = {
  ok: 'OK',
  unsavedChanges: 'UNSAVED_CHANGES',
  unsavedStateUnknown: 'UNSAVED_STATE_UNKNOWN',
  dialogOpen: 'DIALOG_OPEN',
  unknownTarget: 'UNKNOWN_TARGET',
  missingParameter: 'MISSING_PARAMETER',
  unknownTab: 'UNKNOWN_TAB',
  unknownFilter: 'UNKNOWN_FILTER',
  notOnScreen: 'NOT_ON_SCREEN',
  expiredBeforeStart: 'EXPIRED_BEFORE_START',
  readFailed: 'READ_FAILED',
  unknownRequest: 'UNKNOWN_REQUEST',
} as const;

// ── The navigation targets ───────────────────────────────────────────────────────────────────────────────────────────

export interface ScreenTargetFilter {
  /** The query parameter, e.g. `customerId` */
  key: string;
}

/** A screen the user can open: a menu entry, a record screen or a page below one. */
export interface ScreenTarget {
  /** Stable key the agent passes to openScreen: the menu key, the record kind, or a dotted path below one */
  key: string;
  /** What the user calls it (translated) */
  label: string;
  /** The menu groups above it (translated), e.g. `Maintenance` */
  group: string | null;
  /** The route with `:parameter` segments, e.g. `/orders/:orderId` */
  routePattern: string;
  /** The route parameters in order (a nested record screen has two) */
  parameters: string[];
  /** The tabs (route segments after the pattern) */
  tabs: string[];
  /** The URL filters of a list screen */
  filters: ScreenTargetFilter[];
  /** The views of a list screen (`?view=`) */
  views: string[];
}

// ── The adapters an application supplies ─────────────────────────────────────────────────────────────────────────────

/** Where the browser is. */
export interface ScreenLocation {
  /** The route without the basePath, with the query string */
  route: string;
  /** The document title or page heading */
  title: string;
}

/** Why a navigation would lose the user's work. */
export interface ScreenNavigationBlocker {
  outcome: string;
  /** A short sentence for the model */
  reason: string;
}

export interface ScreenOpenRequest {
  recordIds: number[];
  tab: string | null;
  filters: Record<string, string>;
}

/** The screen as Markdown, with the references `highlightOnScreen` takes. */
export interface ScreenSnapshot {
  markdown: string;
  route: string;
  title: string;
  /** reference → label, for the answer of a mark */
  references: Record<string, string>;
}

export interface ScreenMarkResult {
  /** reference → label of the marked elements */
  marked: Record<string, string>;
  /** references that are not on the screen */
  missing: string[];
  /** references marked but out of view or covered */
  outOfView: string[];
  /** references inside a collapsed section: the section header is marked instead */
  collapsed: string[];
}

/** Reads the navigation the user really has (rights applied). */
export interface ScreenTargetSource {
  listTargets(): ScreenTarget[];
}

/** Navigates with the application's router and guards. */
export interface ScreenNavigator {
  describeBlockers(): ScreenNavigationBlocker | null;
  open(target: ScreenTarget, request: ScreenOpenRequest): Promise<ScreenLocation>;
  currentLocation(): ScreenLocation;
}

/** Turns the current screen into Markdown. */
export interface ScreenReader {
  read(options: { focus?: string; maxChars: number }): ScreenSnapshot;
}

/** Marks elements by the references of the newest read. */
export interface ScreenHighlighter {
  mark(references: string[], note: string | null): ScreenMarkResult;
  clear(): void;
}

export interface ScreenAdapters {
  targets: ScreenTargetSource;
  navigator: ScreenNavigator;
  reader: ScreenReader;
  highlighter: ScreenHighlighter;
  /** Whether the client is visible now */
  isVisible: () => boolean;
}

// ── Helpers ──────────────────────────────────────────────────────────────────────────────────────────────────────────

/** The arguments of a delivery as an object; anything else is empty. */
export const parseClientToolArguments = (argumentsJson: string): Record<string, unknown> => {
  try {
    const parsed: unknown = JSON.parse(argumentsJson);
    return typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed) ? (parsed as Record<string, unknown>) : {};
  } catch {
    return {};
  }
};

/** A text cut to the answer's cap. */
export const capAnswerText = (text: string): string =>
  text.length <= MAX_ANSWER_TEXT_CHARS ? text : `${text.slice(0, MAX_ANSWER_TEXT_CHARS - 1)}…`;

/** Lower-case words of a search text. */
const searchWordsOf = (searchText: string): string[] =>
  searchText
    .toLowerCase()
    .split(/\s+/)
    .filter(word => word !== '');

/** One line of a target in the `listScreens` answer. */
export const formatScreenTargetLine = (target: ScreenTarget): string => {
  const parts = [target.key, target.group ? `${target.group} › ${target.label}` : target.label];
  if (target.parameters.length > 0) parts[1] = `${parts[1]} (${target.parameters.join(', ')})`;
  if (target.tabs.length > 0) parts.push(`tabs ${target.tabs.join(', ')}`);
  if (target.filters.length > 0) parts.push(`filters ${target.filters.map(filter => filter.key).join(', ')}`);
  if (target.views.length > 0) parts.push(`views ${target.views.join(', ')}`);
  return `- ${parts.join(' · ')}`;
};

/** The most characters of a `listScreens` answer. */
export const MAX_TARGET_LIST_CHARS = 4500;

/** The `listScreens` answer: the targets whose line contains every word of the search text, capped. */
export const formatScreenTargetList = (targets: ScreenTarget[], searchText: string): string => {
  const searchWords = searchWordsOf(searchText);
  const lines = targets
    .map(formatScreenTargetLine)
    .filter(line => searchWords.every(word => line.toLowerCase().includes(word)));
  if (lines.length === 0) return 'No screen matches. Call listScreens without searchText for all of them.';
  const header = `Screens this user can open (${lines.length}). Pass the key to openScreen.`;
  const kept: string[] = [];
  let chars = header.length;
  for (const line of lines) {
    if (chars + line.length + 1 > MAX_TARGET_LIST_CHARS) {
      kept.push(`… ${lines.length - kept.length} more; narrow with searchText.`);
      break;
    }
    kept.push(line);
    chars += line.length + 1;
  }
  return [header, ...kept].join('\n');
};

/** A reference, a key, a tab or a filter key: what the server lets through. */
const TOKEN_PATTERN = /^[A-Za-z0-9][A-Za-z0-9._-]{0,99}$/;

export const isClientToolToken = (value: unknown): value is string => typeof value === 'string' && TOKEN_PATTERN.test(value);

/** The route of a target for the given record ids and tab; `null` when a record id is missing. */
export const buildTargetRoute = (target: ScreenTarget, recordIds: number[], tab: string | null): string | null => {
  let parameterIndex = 0;
  const segments: string[] = [];
  for (const segment of target.routePattern.split('/').filter(part => part !== '')) {
    if (!segment.startsWith(':')) {
      segments.push(segment);
      continue;
    }
    const recordId = recordIds[parameterIndex];
    parameterIndex += 1;
    if (recordId === undefined || !Number.isInteger(recordId) || recordId <= 0) return null;
    segments.push(String(recordId));
  }
  if (tab) segments.push(tab);
  return `/${segments.join('/')}`;
};
