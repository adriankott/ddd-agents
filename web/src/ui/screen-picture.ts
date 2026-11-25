import type { ScreenPicture, ScreenPictureCapturer, ScreenPictureRenderMode, ScreenPictureRequest, ScreenPictureSize } from "./client-tools";

/**
 * What a picture of the screen cannot show. The same sentences as `AgentUIMCPPackage::PICTURE_LIMITATIONS` of the
 * server (a contract test compares them): the model reads them beside every picture.
 */
export const PICTURE_LIMITATIONS: readonly string[] = [
  'content of other origins (embedded frames, some images and fonts) can be blank or replaced',
  'video, canvas and WebGL content can be blank',
  'password fields show dots; fields the application marks secret or skipped are left out',
  'scrolled-away parts of a scroll container are shown expanded, so the picture can be longer than the window',
];

/** The budgets of one size preset: the long edge, the pixels and the encoded bytes. */
export interface ScreenPicturePreset {
  maxLongEdge: number;
  maxPixels: number;
  maxBytes: number;
}

/** The three size presets; `large` stays below the server's caps (4,000,000 pixels, 614,400 bytes). */
export const SCREEN_PICTURE_PRESETS: Record<ScreenPictureSize, ScreenPicturePreset> = {
  small: { maxLongEdge: 1024, maxPixels: 786_432, maxBytes: 150_000 },
  medium: { maxLongEdge: 1600, maxPixels: 1_920_000, maxBytes: 350_000 },
  large: { maxLongEdge: 2048, maxPixels: 3_750_000, maxBytes: 600_000 },
};

/** The machine mode: 64 grey levels, 75 % of the size, half the byte budget (a picture for the model, not a person). */
export const MACHINE_MODE_GREY_LEVELS = 64;
export const MACHINE_MODE_SIZE_FACTOR = 0.75;
export const MACHINE_MODE_BYTE_FACTOR = 0.5;

/** The most encodings of one capture; each retry scales down from the source. */
export const MAX_PICTURE_ENCODE_ATTEMPTS = 4;

/** The most elements a capture copies (a larger page keeps its first elements). */
export const MAX_PICTURE_ELEMENTS = 8000;

/** The attribute that leaves an element out of a picture. */
export const AGENT_SKIP_ATTRIBUTE = 'data-agent-skip';

/** The attribute that blanks an element's content in a picture (its box stays). */
export const AGENT_SECRET_ATTRIBUTE = 'data-agent-secret';

export interface ScreenPictureDimensions {
  width: number;
  height: number;
  /** The factor from the source size */
  scale: number;
}

/** The byte budget of a preset in a render mode. */
export const computePictureByteBudget = (pictureSize: ScreenPictureSize, renderMode: ScreenPictureRenderMode): number => {
  const preset = SCREEN_PICTURE_PRESETS[pictureSize];
  return Math.floor(renderMode === 'machine' ? preset.maxBytes * MACHINE_MODE_BYTE_FACTOR : preset.maxBytes);
};

/**
 * The size of the picture of a source: within the long edge and the pixels of the preset (never larger than the
 * source), 75 % of it in the machine mode, times `retryScale` (a retry's factor, always taken from the source size).
 */
export const computePictureDimensions = (
  sourceWidth: number,
  sourceHeight: number,
  request: ScreenPictureRequest,
  retryScale: number = 1,
): ScreenPictureDimensions => {
  const preset = SCREEN_PICTURE_PRESETS[request.pictureSize];
  const safeWidth = Math.max(1, sourceWidth);
  const safeHeight = Math.max(1, sourceHeight);
  let scale = Math.min(1, preset.maxLongEdge / Math.max(safeWidth, safeHeight), Math.sqrt(preset.maxPixels / (safeWidth * safeHeight)));
  if (request.renderMode === 'machine') scale *= MACHINE_MODE_SIZE_FACTOR;
  scale *= retryScale;
  return {
    width: Math.max(1, Math.floor(safeWidth * scale)),
    height: Math.max(1, Math.floor(safeHeight * scale)),
    scale,
  };
};

/**
 * The factor of the next attempt after an encoding of `encodedBytes` missed the budget: the bytes shrink about with the
 * pixels, so the side shrinks with the square root, with a margin. Applied to the source size, never to the last
 * picture (no blur piling up over retries).
 */
export const computeRetryScale = (previousRetryScale: number, encodedBytes: number, byteBudget: number): number => {
  if (encodedBytes <= 0) return previousRetryScale;
  return previousRetryScale * Math.min(0.9, Math.sqrt(byteBudget / encodedBytes) * 0.9);
};

/** Turns RGBA pixels grey in place; with `greyLevels` the grey is quantized to that many levels. */
export const convertPixelsToGrey = (pixels: Uint8ClampedArray, greyLevels: number | null = null): void => {
  const levelStep = greyLevels !== null && greyLevels > 1 ? 255 / (greyLevels - 1) : null;
  for (let index = 0; index < pixels.length; index += 4) {
    let grey = 0.2126 * pixels[index] + 0.7152 * pixels[index + 1] + 0.0722 * pixels[index + 2];
    if (levelStep !== null) grey = Math.round(grey / levelStep) * levelStep;
    pixels[index] = grey;
    pixels[index + 1] = grey;
    pixels[index + 2] = grey;
  }
};

/** Whether an element scrolls its own content vertically (its scrolled-away part is shown expanded). */
const isScrollContainer = (element: Element, style: CSSStyleDeclaration): boolean =>
  element.scrollHeight > element.clientHeight + 1 && (style.overflowY === 'auto' || style.overflowY === 'scroll');

/** Copies the computed style of an element onto its clone as inline style. */
const copyComputedStyle = (style: CSSStyleDeclaration, clone: HTMLElement | SVGElement): void => {
  const declarations: string[] = [];
  for (let index = 0; index < style.length; index += 1) {
    const property = style.item(index);
    declarations.push(`${property}:${style.getPropertyValue(property)}`);
  }
  clone.setAttribute('style', declarations.join(';'));
};

/** The value of a form field written into its clone, a password as dots. */
const copyFieldValue = (original: Element, clone: Element): void => {
  if (original instanceof HTMLInputElement && clone instanceof HTMLInputElement) {
    if (original.type === 'password') {
      clone.setAttribute('value', '•'.repeat(Math.min(original.value.length, 24)));
      clone.type = 'text';
    } else if (original.type === 'checkbox' || original.type === 'radio') {
      if (original.checked) clone.setAttribute('checked', '');
      else clone.removeAttribute('checked');
    } else {
      clone.setAttribute('value', original.value);
    }
  } else if (original instanceof HTMLTextAreaElement) {
    clone.textContent = original.value;
  } else if (original instanceof HTMLSelectElement && clone instanceof HTMLSelectElement) {
    Array.from(clone.options).forEach((option, optionIndex) => {
      if (optionIndex === original.selectedIndex) option.setAttribute('selected', '');
      else option.removeAttribute('selected');
    });
  }
};

/** Elements never copied into a picture. */
const LEFT_OUT_TAGS = new Set(['SCRIPT', 'NOSCRIPT', 'TEMPLATE', 'LINK', 'META']);

/**
 * A copy of `root` ready to be drawn: computed styles inlined, form values written (passwords as dots), elements with
 * `data-agent-skip` left out, the content of elements with `data-agent-secret` blanked (the box stays), scroll
 * containers expanded. `copyStyles` false skips the styles (tests).
 */
export const prepareScreenCloneForPicture = (root: Element, copyStyles: boolean = true): Element => {
  const clone = root.cloneNode(false) as Element;
  let copiedElements = 0;
  const copyInto = (original: Element, target: Element): void => {
    const view = original.ownerDocument.defaultView;
    const style = copyStyles && view ? view.getComputedStyle(original) : null;
    if (style && (target instanceof HTMLElement || target instanceof SVGElement)) {
      copyComputedStyle(style, target);
      if (target instanceof HTMLElement && isScrollContainer(original, style)) {
        target.style.overflowY = 'visible';
        target.style.height = 'auto';
        target.style.maxHeight = 'none';
      }
    } else if (!copyStyles && target instanceof HTMLElement && original.scrollHeight > original.clientHeight + 1) {
      target.style.overflowY = 'visible';
      target.style.height = 'auto';
      target.style.maxHeight = 'none';
    }
    copyFieldValue(original, target);
    if (original.hasAttribute(AGENT_SECRET_ATTRIBUTE)) {
      target.textContent = '';
      if (target instanceof HTMLElement) target.style.background = '#9e9e9e';
      return;
    }
    if (original instanceof HTMLTextAreaElement) return;
    for (const childNode of Array.from(original.childNodes)) {
      if (childNode.nodeType === Node.TEXT_NODE) {
        target.appendChild(childNode.cloneNode(false));
        continue;
      }
      if (!(childNode instanceof Element)) continue;
      if (LEFT_OUT_TAGS.has(childNode.tagName) || childNode.hasAttribute(AGENT_SKIP_ATTRIBUTE)) continue;
      if (copiedElements >= MAX_PICTURE_ELEMENTS) return;
      copiedElements += 1;
      const childClone = childNode.cloneNode(false) as Element;
      target.appendChild(childClone);
      copyInto(childNode, childClone);
    }
  };
  copyInto(root, clone);
  return clone;
};

/** Measures the height of a prepared clone laid out at `width`, off screen. */
const measureCloneHeight = (clone: Element, width: number, documentOfRoot: Document): number => {
  const holder = documentOfRoot.createElement('div');
  holder.setAttribute('aria-hidden', 'true');
  holder.style.cssText = `position:fixed;left:-100000px;top:0;width:${width}px;visibility:hidden;pointer-events:none;`;
  const measured = clone.cloneNode(true) as Element;
  holder.appendChild(measured);
  documentOfRoot.body.appendChild(holder);
  try {
    return Math.ceil(Math.max(holder.scrollHeight, (measured as HTMLElement).scrollHeight ?? 0));
  } finally {
    holder.remove();
  }
};

const loadImage = (url: string): Promise<HTMLImageElement> =>
  new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error('the screen could not be drawn'));
    image.src = url;
  });

const encodeCanvas = (canvas: HTMLCanvasElement, mimeType: string, quality: number): Promise<Blob | null> =>
  new Promise(resolve => {
    try {
      canvas.toBlob(blob => resolve(blob), mimeType, quality);
    } catch {
      resolve(null);
    }
  });

const encodeBase64 = async (blob: Blob): Promise<string> => {
  const bytes = new Uint8Array(await blob.arrayBuffer());
  let binary = '';
  const chunkSize = 0x8000;
  for (let offset = 0; offset < bytes.length; offset += chunkSize) {
    binary += String.fromCharCode(...bytes.subarray(offset, offset + chunkSize));
  }
  return btoa(binary);
};

export interface DomScreenPictureCapturerOptions {
  /** The element pictured; default the document element */
  root?: () => Element | null;
  /** The quality of the first encoding (default 0.8); a retry lowers it once to 0.65 */
  quality?: number;
}

/**
 * The DOM default of `ScreenPictureCapturer`: draws a styled copy of the page (SVG foreignObject onto a canvas) at the
 * size of the preset, grey in `greyscale` and `machine` (64 levels), and encodes it as WebP (JPEG where the browser
 * has no WebP encoder) within the byte budget; a miss retries from the source size, at most
 * `MAX_PICTURE_ENCODE_ATTEMPTS` times. No dependency; what it cannot show is `PICTURE_LIMITATIONS`. Returns null when
 * the browser refuses (a tainted canvas, no canvas), and the runner answers `PICTURE_UNAVAILABLE`.
 */
export const createDomScreenPictureCapturer = (options: DomScreenPictureCapturerOptions = {}): ScreenPictureCapturer => ({
  capture: async (request: ScreenPictureRequest): Promise<ScreenPicture | null> => {
    if (typeof document === 'undefined') return null;
    const root = options.root?.() ?? document.documentElement;
    if (!root) return null;
    try {
      const sourceWidth = Math.max(1, Math.ceil(window.innerWidth || root.clientWidth));
      const clone = prepareScreenCloneForPicture(root);
      const sourceHeight = Math.max(1, Math.min(measureCloneHeight(clone, sourceWidth, document), sourceWidth * 8));
      const serialized = new XMLSerializer().serializeToString(clone);
      const svg =
        `<svg xmlns="http://www.w3.org/2000/svg" width="${sourceWidth}" height="${sourceHeight}">` +
        `<foreignObject x="0" y="0" width="100%" height="100%">${serialized}</foreignObject></svg>`;
      const image = await loadImage(`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`);
      const byteBudget = computePictureByteBudget(request.pictureSize, request.renderMode);
      let retryScale = 1;
      let quality = options.quality ?? 0.8;
      for (let attempt = 1; attempt <= MAX_PICTURE_ENCODE_ATTEMPTS; attempt += 1) {
        const dimensions = computePictureDimensions(sourceWidth, sourceHeight, request, retryScale);
        const canvas = document.createElement('canvas');
        canvas.width = dimensions.width;
        canvas.height = dimensions.height;
        const context = canvas.getContext('2d');
        if (!context) return null;
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, dimensions.width, dimensions.height);
        context.drawImage(image, 0, 0, dimensions.width, dimensions.height);
        if (request.renderMode !== 'colour') {
          const imageData = context.getImageData(0, 0, dimensions.width, dimensions.height);
          convertPixelsToGrey(imageData.data, request.renderMode === 'machine' ? MACHINE_MODE_GREY_LEVELS : null);
          context.putImageData(imageData, 0, 0);
        }
        let blob = await encodeCanvas(canvas, 'image/webp', quality);
        if (blob && blob.type !== 'image/webp') blob = await encodeCanvas(canvas, 'image/jpeg', quality);
        if (!blob) return null;
        if (blob.size <= byteBudget || attempt === MAX_PICTURE_ENCODE_ATTEMPTS) {
          if (blob.size > byteBudget) return null;
          return {
            base64: await encodeBase64(blob),
            mimeType: blob.type === 'image/webp' ? 'image/webp' : 'image/jpeg',
            width: dimensions.width,
            height: dimensions.height,
            byteSize: blob.size,
          };
        }
        retryScale = computeRetryScale(retryScale, blob.size, byteBudget);
        quality = Math.min(quality, 0.65);
      }
      return null;
    } catch {
      return null;
    }
  },
});
