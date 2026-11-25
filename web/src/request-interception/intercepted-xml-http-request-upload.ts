/** The upload side of the `XMLHttpRequest` stand-in (`xhr.upload`) and the events both sides dispatch. */

export type XhrEventName = "loadstart" | "progress" | "load" | "error" | "abort" | "timeout" | "loadend" | "readystatechange";
export type XhrEventHandler = ((this: unknown, event: Event) => unknown) | null;

export const createProgressEvent = (type: string, loaded: number, total: number): Event =>
  typeof ProgressEvent === "function"
    ? new ProgressEvent(type, { lengthComputable: total > 0, loaded, total })
    : new Event(type);

/** The event target of `xhr.upload`: listeners and `on…` handlers for the upload's progress. */
export class InterceptedXMLHttpRequestUpload extends EventTarget {
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
