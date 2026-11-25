import {
  buildTargetRoute,
  CLIENT_TOOL_OUTCOMES,
  type ClientToolAnswer,
  type ClientToolRequestDelivery,
  capAnswerText,
  formatScreenTargetList,
  isClientToolToken,
  MAX_ANSWER_TEXT_CHARS,
  MIN_SECONDS_LEFT_TO_START,
  parseClientToolArguments,
  type ClientToolRequestType,
  type ScreenAdapters,
  type ScreenPictureRenderMode,
  type ScreenPictureSize,
  type ScreenTarget,
} from "./client-tools";

/** The request types of the package; a handler of the application never replaces them. */
const PACKAGE_REQUEST_TYPES: string[] = ['READ_SCREEN', 'LIST_TARGETS', 'OPEN_TARGET', 'HIGHLIGHT', 'SET_WINDOW_MODE'];

/** The request types the agent window steps aside for by default. */
const STEP_ASIDE_REQUEST_TYPES: ClientToolRequestType[] = ['OPEN_TARGET', 'HIGHLIGHT', 'READ_SCREEN'];

const PICTURE_SIZES: ScreenPictureSize[] = ['small', 'medium', 'large'];

const RENDER_MODES: ScreenPictureRenderMode[] = ['colour', 'greyscale', 'machine'];

/**
 * The most characters of screen content in one answer: half the answer's cap ({@link MAX_ANSWER_TEXT_CHARS}), the
 * same number as the server's cut (`AgentUIToolResponseDto::MAX_CONTENT_CHARS`, a contract test compares them). With
 * 6,000 a list page was cut before its table; the reader's own caps (rows, cells, section text) keep a read short.
 */
export const MAX_SCREEN_CHARS = MAX_ANSWER_TEXT_CHARS / 2;

/** How many request ids a runner remembers, so a redelivery never runs a request twice. */
const REMEMBERED_REQUEST_IDS = 50;

/** Posts an answer; the transport is the application's. */
export type ClientToolAnswerSender = (requestId: number, answer: ClientToolAnswer) => Promise<void>;

/**
 * Runs the requests the run-state read delivers, one at a time, through the application's adapters, and posts each
 * answer once. Generic: it knows the four request types and the refusal codes, nothing of a menu or a route.
 */
export class ClientToolRequestRunner {
  protected readonly handledRequestIds: number[] = [];

  protected running: Promise<void> = Promise.resolve();

  protected readonly adapters: ScreenAdapters;

  constructor(adapters: ScreenAdapters) {
    this.adapters = adapters;
  }

  /**
   * Takes a delivery and posts its answer through `sendAnswer`; a request already taken is ignored (the server
   * redelivers an unanswered one after 5 s).
   */
  accept(delivery: ClientToolRequestDelivery, sendAnswer: ClientToolAnswerSender): void {
    if (this.handledRequestIds.includes(delivery.id)) return;
    this.handledRequestIds.push(delivery.id);
    if (this.handledRequestIds.length > REMEMBERED_REQUEST_IDS) this.handledRequestIds.shift();
    const receivedAt = Date.now();
    this.running = this.running.then(() => this.runAndAnswer(delivery, receivedAt, sendAnswer));
  }

  /** Resolves when every accepted request was answered (tests). */
  whenIdle(): Promise<void> {
    return this.running;
  }

  protected async runAndAnswer(delivery: ClientToolRequestDelivery, receivedAt: number, sendAnswer: ClientToolAnswerSender): Promise<void> {
    const answer = await this.run(delivery, receivedAt);
    try {
      await sendAnswer(delivery.id, { ...answer, text: capAnswerText(answer.text) });
    } catch {
      // the tool answers "no answer" after its wait; nothing else to do in the tab
    }
  }

  /** The answer of one request. Exposed for the tests. */
  async run(delivery: ClientToolRequestDelivery, receivedAt: number = Date.now()): Promise<ClientToolAnswer> {
    const secondsLeft = delivery.secondsLeft - (Date.now() - receivedAt) / 1000;
    if (secondsLeft < MIN_SECONDS_LEFT_TO_START) return refusal(CLIENT_TOOL_OUTCOMES.expiredBeforeStart);
    const parameters = parseClientToolArguments(delivery.argumentsJson);
    try {
      switch (delivery.requestType) {
        case 'READ_SCREEN':
          if (parameters.format === 'picture') {
            await this.stepAgentWindowAside('READ_SCREEN');
            try {
              return await this.readScreenPicture(parameters);
            } finally {
              await this.adapters.agentWindow?.restore();
            }
          }
          return this.readScreen(parameters);
        case 'LIST_TARGETS':
          return this.listTargets(parameters);
        case 'OPEN_TARGET':
          return await this.openTarget(parameters);
        case 'HIGHLIGHT':
          await this.stepAgentWindowAside('HIGHLIGHT');
          return this.highlight(parameters);
        case 'SET_WINDOW_MODE':
          return await this.setWindowMode(parameters);
        default:
          return await this.runRequestOfApplication(delivery, parameters);
      }
    } catch {
      return refusal(CLIENT_TOOL_OUTCOMES.readFailed);
    }
  }

  /** A request type the application registered: its handler, else `UNKNOWN_REQUEST`. */
  protected async runRequestOfApplication(delivery: ClientToolRequestDelivery, parameters: Record<string, unknown>): Promise<ClientToolAnswer> {
    const handler = PACKAGE_REQUEST_TYPES.includes(delivery.requestType) ? undefined : this.adapters.requestHandlers?.[delivery.requestType];
    if (!handler) return refusal(CLIENT_TOOL_OUTCOMES.unknownRequest);
    return handler(parameters, delivery);
  }

  /** Lets the agent window make room before the agent works on the screen (the default for the screen requests). */
  protected async stepAgentWindowAside(requestType: ClientToolRequestType): Promise<void> {
    const agentWindow = this.adapters.agentWindow;
    if (!agentWindow) return;
    const shouldStepAside = agentWindow.shouldStepAsideBefore
      ? agentWindow.shouldStepAsideBefore(requestType)
      : STEP_ASIDE_REQUEST_TYPES.includes(requestType);
    if (shouldStepAside) await agentWindow.stepAside(requestType);
  }

  protected async setWindowMode(parameters: Record<string, unknown>): Promise<ClientToolAnswer> {
    const agentWindow = this.adapters.agentWindow;
    if (!agentWindow || !isClientToolToken(parameters.mode)) return refusal(CLIENT_TOOL_OUTCOMES.unknownMode);
    if (parameters.mode === 'restore') {
      await agentWindow.restore();
      return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: '', details: { mode: 'restore' } };
    }
    const accepted = await agentWindow.setMode(parameters.mode);
    if (!accepted) return refusal(CLIENT_TOOL_OUTCOMES.unknownMode);
    return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: '', details: { mode: parameters.mode } };
  }

  protected async readScreenPicture(parameters: Record<string, unknown>): Promise<ClientToolAnswer> {
    const capturer = this.adapters.picture;
    if (!capturer) return refusal(CLIENT_TOOL_OUTCOMES.pictureUnavailable);
    const pictureSize = PICTURE_SIZES.find(size => size === parameters.pictureSize) ?? 'medium';
    const renderMode = RENDER_MODES.find(mode => mode === parameters.renderMode) ?? 'colour';
    const picture = await capturer.capture({ pictureSize, renderMode });
    if (!picture) return refusal(CLIENT_TOOL_OUTCOMES.pictureUnavailable);
    const location = this.adapters.navigator.currentLocation();
    return {
      outcome: CLIENT_TOOL_OUTCOMES.ok,
      text: '',
      details: {
        route: location.route,
        visible: this.adapters.isVisible(),
        width: picture.width,
        height: picture.height,
        bytes: picture.byteSize,
        renderMode,
      },
      pictureBase64: picture.base64,
    };
  }

  protected readScreen(parameters: Record<string, unknown>): ClientToolAnswer {
    const focus = isClientToolToken(parameters.focus) ? parameters.focus : undefined;
    const snapshot = this.adapters.reader.read({ focus, maxChars: MAX_SCREEN_CHARS });
    return {
      outcome: CLIENT_TOOL_OUTCOMES.ok,
      text: snapshot.markdown,
      details: { route: snapshot.route, visible: this.adapters.isVisible(), chars: snapshot.markdown.length },
    };
  }

  protected listTargets(parameters: Record<string, unknown>): ClientToolAnswer {
    const searchText = typeof parameters.searchText === 'string' ? parameters.searchText : '';
    return {
      outcome: CLIENT_TOOL_OUTCOMES.ok,
      text: formatScreenTargetList(this.adapters.targets.listTargets(), searchText),
      details: {},
    };
  }

  protected async openTarget(parameters: Record<string, unknown>): Promise<ClientToolAnswer> {
    const target = this.findTarget(parameters.target);
    if (!target) return refusal(CLIENT_TOOL_OUTCOMES.unknownTarget);
    const tab = isClientToolToken(parameters.tab) ? parameters.tab : null;
    if (tab !== null && !target.tabs.includes(tab)) return refusal(CLIENT_TOOL_OUTCOMES.unknownTab);
    const filters: Record<string, string> = {};
    for (const filter of Array.isArray(parameters.filters) ? parameters.filters : []) {
      const [key, ...valueParts] = String(filter).split('=');
      if (!target.filters.some(targetFilter => targetFilter.key === key) || valueParts.length === 0) {
        return refusal(CLIENT_TOOL_OUTCOMES.unknownFilter);
      }
      filters[key] = valueParts.join('=');
    }
    const recordIds = typeof parameters.recordId === 'number' ? [parameters.recordId] : [];
    if (buildTargetRoute(target, recordIds, tab) === null) return refusal(CLIENT_TOOL_OUTCOMES.missingParameter);
    const blocker = this.adapters.navigator.describeBlockers();
    if (blocker) return { outcome: blocker.outcome, text: blocker.reason, details: {} };
    await this.stepAgentWindowAside('OPEN_TARGET');
    const openRequest = { recordIds, tab, filters };
    try {
      this.adapters.pathGuide?.guide(target, openRequest);
    } catch {
      // a guide is decoration: the navigation goes on without it
    }
    const location = await this.adapters.navigator.open(target, openRequest);
    const details = { route: location.route, title: location.title };
    if (parameters.includeScreen !== true) return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: '', details };
    const snapshot = this.adapters.reader.read({ maxChars: MAX_SCREEN_CHARS });
    return { outcome: CLIENT_TOOL_OUTCOMES.ok, text: snapshot.markdown, details };
  }

  protected highlight(parameters: Record<string, unknown>): ClientToolAnswer {
    const references = (Array.isArray(parameters.references) ? parameters.references : []).filter(isClientToolToken);
    const note = typeof parameters.note === 'string' && parameters.note.trim() !== '' ? parameters.note : null;
    const result = this.adapters.highlighter.mark(references, note);
    const markedReferences = Object.keys(result.marked);
    const lines = markedReferences.map(reference => {
      const suffix = result.outOfView.includes(reference)
        ? ' (scrolled into view)'
        : result.collapsed.includes(reference)
          ? ' (inside a closed section: its header is marked)'
          : '';
      return `Marked ${reference} "${result.marked[reference]}"${suffix}.`;
    });
    if (result.missing.length > 0) lines.push(`Not on the screen: ${result.missing.join(', ')}.`);
    const outcome = markedReferences.length > 0 ? CLIENT_TOOL_OUTCOMES.ok : CLIENT_TOOL_OUTCOMES.notOnScreen;
    return { outcome, text: lines.join('\n'), details: { marked: markedReferences.length } };
  }

  protected findTarget(key: unknown): ScreenTarget | null {
    if (!isClientToolToken(key)) return null;
    return this.adapters.targets.listTargets().find(target => target.key === key) ?? null;
  }
}

const refusal = (outcome: string): ClientToolAnswer => ({ outcome, text: '', details: {} });
