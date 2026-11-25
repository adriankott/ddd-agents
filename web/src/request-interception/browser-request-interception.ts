/**
 * The browser-level request stand-in of the capture and preview pages: `XMLHttpRequest` (the transport of axios's
 * browser adapter and of uploads with progress) and, where an install asks for it, `window.fetch` are replaced for the
 * duration of an install and send every request into one handler, whatever HTTP client the application uses.
 *
 * The handler answers with a `Response` or throws: `fetch` then rejects with the thrown error, an `XMLHttpRequest`
 * fails like a network error (status 0, `error` and `loadend`, no body, no header). A handler that never settles
 * leaves the request pending. Nothing of the request reaches the network unless the handler sends it itself
 * (`forward()`, or its own copy of the original `fetch`).
 */

/** A request as the handler sees it, the same for `fetch` and `XMLHttpRequest`. */
export interface InterceptedRequest {
  /** Upper case (`GET`, `POST`, …). */
  method: string;
  /** Absolute, resolved against the page's origin. A fresh object per request; the handler may change it. */
  url: URL;
  headers: Headers;
  /** The body the client passed (`fetch`'s, or the argument of `XMLHttpRequest.send`, also for a GET), or null. */
  body: BodyInit | null;
  signal: AbortSignal | undefined;
  transport: "fetch" | "xhr";
  /** Sends the request unchanged through the original `fetch` (an `XMLHttpRequest` with its own credentials mode). */
  forward: () => Promise<Response>;
}

export type InterceptedRequestHandler = (request: InterceptedRequest) => Promise<Response>;

type FetchInput = RequestInfo | URL;

/**
 * An answer without any header, as the former axios adapters answered (`headers: {}`): a string body would add a
 * `Content-Type`, the bytes of it do not.
 */
export const createHeaderlessResponse = (body: string | null, status: number, statusText = ""): Response =>
  new Response(body === null ? null : new TextEncoder().encode(body), { status, statusText });

/** A `Request` of any realm (an iframe's, jsdom's, Node's) — never `instanceof`, which holds only for this realm. */
export const isFetchRequest = (input: unknown): input is Request =>
  Object.prototype.toString.call(input) === "[object Request]";

const pageOrigin = (): string =>
  typeof window !== "undefined" && window.location ? window.location.origin : "http://localhost";

const copyHeaders = (target: Headers, source: HeadersInit | undefined) => {
  if (source === undefined) return;
  new Headers(source).forEach((value, name) => target.set(name, value));
};

/** The `fetch` arguments as one request: a `Request` of any realm, a `URL` or a string, with `init` taking precedence. */
export const readFetchRequest = async (
  input: FetchInput,
  init: RequestInit | undefined,
  originalFetch: typeof fetch,
): Promise<InterceptedRequest> => {
  const fromRequest = isFetchRequest(input) ? input : undefined;
  const headers = new Headers();
  if (fromRequest) fromRequest.headers.forEach((value, name) => headers.set(name, value));
  copyHeaders(headers, init?.headers);
  const initBody = init?.body ?? null;
  const body =
    initBody !== null
      ? initBody
      : fromRequest && fromRequest.body !== null
        ? await fromRequest.clone().arrayBuffer()
        : null;
  return {
    method: (init?.method ?? fromRequest?.method ?? "GET").toUpperCase(),
    url: new URL(fromRequest ? fromRequest.url : String(input), pageOrigin()),
    headers,
    body,
    signal: init?.signal ?? fromRequest?.signal ?? undefined,
    transport: "fetch",
    forward: () => originalFetch(input, init),
  };
};

type XhrEventName = "loadstart" | "progress" | "load" | "error" | "abort" | "timeout" | "loadend" | "readystatechange";
type XhrEventHandler = ((this: unknown, event: Event) => unknown) | null;

const createProgressEvent = (type: string, loaded: number, total: number): Event =>
  typeof ProgressEvent === "function"
    ? new ProgressEvent(type, { lengthComputable: total > 0, loaded, total })
    : new Event(type);

const bodySize = (body: BodyInit | null): number => {
  if (body === null) return 0;
  if (typeof body === "string") return new TextEncoder().encode(body).byteLength;
  if (typeof Blob !== "undefined" && body instanceof Blob) return body.size;
  if (body instanceof ArrayBuffer) return body.byteLength;
  if (ArrayBuffer.isView(body)) return body.byteLength;
  return 0;
};

/** The event target of `xhr.upload`: listeners and `on…` handlers for the upload's progress. */
class InterceptedXMLHttpRequestUpload extends EventTarget {
  onloadstart: XhrEventHandler = null;
  onprogress: XhrEventHandler = null;
  onload: XhrEventHandler = null;
  onerror: XhrEventHandler = null;
  onabort: XhrEventHandler = null;
  ontimeout: XhrEventHandler = null;
  onloadend: XhrEventHandler = null;

  emit(type: XhrEventName, loaded = 0, total = 0) {
    const event = createProgressEvent(type, loaded, total);
    this.dispatchEvent(event);
    (this[`on${type}` as keyof this] as XhrEventHandler)?.call(this, event);
  }
}

const invalidState = (message: string) => new DOMException(message, "InvalidStateError");

/** Builds the `XMLHttpRequest` class an install puts on the page: every request goes to `handle`. */
const createInterceptedXMLHttpRequest = (handle: InterceptedRequestHandler, originalFetch: typeof fetch) =>
  class InterceptedXMLHttpRequest extends EventTarget {
    static readonly UNSENT = 0;
    static readonly OPENED = 1;
    static readonly HEADERS_RECEIVED = 2;
    static readonly LOADING = 3;
    static readonly DONE = 4;
    readonly UNSENT = 0;
    readonly OPENED = 1;
    readonly HEADERS_RECEIVED = 2;
    readonly LOADING = 3;
    readonly DONE = 4;

    readyState = 0;
    status = 0;
    statusText = "";
    responseURL = "";
    responseType: XMLHttpRequestResponseType = "";
    timeout = 0;
    withCredentials = false;
    readonly upload = new InterceptedXMLHttpRequestUpload();
    readonly responseXML = null;

    onreadystatechange: XhrEventHandler = null;
    onloadstart: XhrEventHandler = null;
    onprogress: XhrEventHandler = null;
    onload: XhrEventHandler = null;
    onerror: XhrEventHandler = null;
    onabort: XhrEventHandler = null;
    ontimeout: XhrEventHandler = null;
    onloadend: XhrEventHandler = null;

    private method = "GET";
    private requestUrl = "";
    private requestHeaders = new Headers();
    private responseHeaders = new Headers();
    private responseValue: unknown = "";
    private responseTextValue = "";
    private sent = false;
    private settled = false;
    private controller: AbortController | undefined;
    private timer: ReturnType<typeof setTimeout> | undefined;

    get response(): unknown {
      if (this.responseType === "" || this.responseType === "text") return this.responseText;
      return this.readyState === 4 ? this.responseValue : null;
    }

    get responseText(): string {
      if (this.responseType !== "" && this.responseType !== "text") {
        throw invalidState(`responseText is not available for responseType "${this.responseType}"`);
      }
      return this.readyState >= 3 ? this.responseTextValue : "";
    }

    open(method: string, url: string | URL) {
      // A running request is dropped without events, as a browser does.
      if (this.sent && !this.settled) this.settle();
      this.controller?.abort();
      this.controller = undefined;
      this.method = method.toUpperCase();
      this.requestUrl = String(url);
      this.requestHeaders = new Headers();
      this.responseHeaders = new Headers();
      this.responseValue = "";
      this.responseTextValue = "";
      this.status = 0;
      this.statusText = "";
      this.responseURL = "";
      this.sent = false;
      this.settled = false;
      this.setReadyState(1);
    }

    setRequestHeader(name: string, value: string) {
      if (this.readyState !== 1 || this.sent) throw invalidState("setRequestHeader needs an opened, unsent request");
      this.requestHeaders.append(name, value);
    }

    overrideMimeType() {}

    getAllResponseHeaders(): string {
      if (this.readyState < 2) return "";
      let lines = "";
      this.responseHeaders.forEach((value, name) => {
        lines += `${name}: ${value}\r\n`;
      });
      return lines;
    }

    getResponseHeader(name: string): string | null {
      return this.readyState < 2 ? null : this.responseHeaders.get(name);
    }

    send(body?: Document | XMLHttpRequestBodyInit | null) {
      if (this.readyState !== 1 || this.sent) throw invalidState("send needs an opened, unsent request");
      this.sent = true;
      const controller = new AbortController();
      this.controller = controller;
      const passedBody = body === undefined || body === null ? null : (body as BodyInit);
      // A browser sends no body with a GET or HEAD; the handler still sees what the client passed.
      const requestBody = this.method === "GET" || this.method === "HEAD" ? null : passedBody;
      const headers = new Headers(this.requestHeaders);
      const url = new URL(this.requestUrl, pageOrigin());
      const credentials: RequestCredentials = this.withCredentials ? "include" : "same-origin";
      const method = this.method;
      const request: InterceptedRequest = {
        method,
        url,
        headers,
        body: passedBody,
        signal: controller.signal,
        transport: "xhr",
        forward: () =>
          originalFetch(url.href, {
            method,
            headers: new Headers(headers),
            body: requestBody,
            credentials,
            signal: controller.signal,
          }),
      };
      const responseUrl = url.href;
      if (this.timeout > 0) this.timer = setTimeout(() => this.finishWithoutResponse("timeout"), this.timeout);
      this.emit("loadstart");
      const size = bodySize(requestBody);
      if (requestBody !== null) {
        this.upload.emit("loadstart", 0, size);
        this.upload.emit("progress", size, size);
        this.upload.emit("load", size, size);
        this.upload.emit("loadend", size, size);
      }
      Promise.resolve()
        .then(() => handle(request))
        .then(
          (response) => this.receive(controller, response, responseUrl),
          () => {
            if (this.controller === controller) this.finishWithoutResponse("error");
          },
        );
    }

    abort() {
      if (!this.sent || this.settled) return;
      this.finishWithoutResponse("abort");
      this.readyState = 0;
    }

    private async receive(controller: AbortController, response: Response, responseUrl: string) {
      if (this.controller !== controller || this.settled) return;
      try {
        this.status = response.status;
        this.statusText = response.statusText;
        this.responseURL = responseUrl;
        this.responseHeaders = new Headers(response.headers);
        this.setReadyState(2);
        const value = await this.readBody(response);
        if (this.controller !== controller || this.settled) return;
        this.responseValue = value;
        this.setReadyState(3);
        const size = typeof this.responseTextValue === "string" ? this.responseTextValue.length : 0;
        this.emit("progress", size, size);
        this.settle();
        this.setReadyState(4);
        this.emit("load", size, size);
        this.emit("loadend", size, size);
      } catch {
        if (this.controller === controller && !this.settled) this.finishWithoutResponse("error");
      }
    }

    private async readBody(response: Response): Promise<unknown> {
      switch (this.responseType) {
        case "json": {
          const text = await response.text();
          this.responseTextValue = text;
          try {
            return text === "" ? null : JSON.parse(text);
          } catch {
            return null;
          }
        }
        case "blob":
          return response.blob();
        case "arraybuffer":
          return response.arrayBuffer();
        case "document":
          return null;
        default: {
          const text = await response.text();
          this.responseTextValue = text;
          return text;
        }
      }
    }

    /** A request that ends without a response — an error, an abort or a timeout: status 0, nothing of a body. */
    private finishWithoutResponse(type: "error" | "abort" | "timeout") {
      if (this.settled) return;
      this.settle();
      this.controller?.abort();
      this.status = 0;
      this.statusText = "";
      this.responseHeaders = new Headers();
      this.responseValue = null;
      this.responseTextValue = "";
      this.setReadyState(4);
      this.emit(type);
      this.emit("loadend");
    }

    private settle() {
      this.settled = true;
      if (this.timer !== undefined) clearTimeout(this.timer);
      this.timer = undefined;
    }

    private setReadyState(state: number) {
      this.readyState = state;
      this.emit("readystatechange");
    }

    private emit(type: XhrEventName, loaded = 0, total = 0) {
      const event = type === "readystatechange" ? new Event(type) : createProgressEvent(type, loaded, total);
      this.dispatchEvent(event);
      (this[`on${type}` as keyof this] as XhrEventHandler)?.call(this, event);
    }
  };

type GlobalScope = { fetch?: typeof fetch; XMLHttpRequest?: typeof XMLHttpRequest };

/** The scopes that carry the page's `fetch` and `XMLHttpRequest` (the window and the global object, once each). */
const globalScopes = (): GlobalScope[] => {
  const scopes: GlobalScope[] = [globalThis as GlobalScope];
  if (typeof window !== "undefined" && (window as unknown) !== globalThis) scopes.push(window as GlobalScope);
  return scopes;
};

/** The page's `fetch` as it is now (the window's), callable after an install replaced it. */
const capturePageFetch = (): typeof fetch => {
  const pageFetch = (typeof window !== "undefined" ? window.fetch : globalThis.fetch) as typeof fetch;
  return (input, init) => pageFetch.call(globalThis, input, init);
};

/** Puts `value` on every global scope under `name` until the returned function restores what was there. */
const replaceGlobal = <Name extends keyof GlobalScope>(name: Name, value: GlobalScope[Name]): (() => void) => {
  const saved = globalScopes().map((scope) => ({ scope, value: scope[name] }));
  for (const { scope } of saved) scope[name] = value;
  return () => {
    for (const entry of saved) entry.scope[name] = entry.value;
  };
};

/**
 * Replaces `XMLHttpRequest` on the page until the returned function is called; `fetch` stays as it is. A handler's
 * `forward()` goes through the page's `fetch` as it was at the install.
 */
export const interceptXMLHttpRequests = (handle: InterceptedRequestHandler): (() => void) =>
  replaceGlobal(
    "XMLHttpRequest",
    createInterceptedXMLHttpRequest(handle, capturePageFetch()) as unknown as typeof XMLHttpRequest,
  );

/** Replaces `fetch` on the page until the returned function is called; `XMLHttpRequest` stays as it is. */
export const interceptFetchRequests = (handle: InterceptedRequestHandler): (() => void) => {
  const originalFetch = capturePageFetch();
  const interceptedFetch = async (input: FetchInput, init?: RequestInit): Promise<Response> =>
    handle(await readFetchRequest(input, init, originalFetch));
  return replaceGlobal("fetch", interceptedFetch as typeof fetch);
};

/**
 * A `fetch` input this realm's `Request` constructor reads correctly: a `Request` of another realm becomes one of this
 * realm with the same URL, method, headers, body and signal; anything else is returned as it is.
 */
export const toSameRealmFetchInput = async (input: FetchInput): Promise<FetchInput> => {
  if (!isFetchRequest(input) || (input as unknown) instanceof Request) return input;
  const foreign: Request = input;
  const body = foreign.body !== null ? await foreign.clone().arrayBuffer() : null;
  return new Request(foreign.url, {
    method: foreign.method,
    headers: new Headers(foreign.headers),
    body,
    // A signal of another realm is not accepted by this realm's constructor.
    signal: foreign.signal instanceof AbortSignal ? foreign.signal : undefined,
  });
};
