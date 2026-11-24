import type { ScreenReader, ScreenSnapshot } from "./client-tools";
import {
  type ButtonFacts,
  capValue,
  type FieldFacts,
  fieldReferenceNameOf,
  formatButtons,
  formatFieldLine,
  formatTable,
  joinScreenParts,
  MAX_TABLE_ROWS,
  oneLine,
  ReferenceNames,
  slugOf,
  type TableFacts,
} from "./screen-markdown";

/** The attributes an application (and `@mgamadeus/ddd-admin-ui`) sets for the agent. */
export const AGENT_ATTRIBUTES = {
  /** A stable handle: `a.<value>` */
  anchor: 'data-agent-anchor',
  /** The value is never read (a key, a token) */
  secret: 'data-agent-secret',
  /** The element and everything in it is never read (the agent window itself) */
  skip: 'data-agent-skip',
  /** The key of a table row (@mgamadeus/ddd-admin-ui) */
  rowKey: 'data-row-key',
} as const;

/** Elements never read. */
const SKIPPED_SELECTOR = `[${AGENT_ATTRIBUTES.skip}], script, style, noscript, template, svg, [aria-hidden="true"], [hidden]`;

/** Fields whose value is never read. */
const SECRET_FIELD_SELECTOR = `[${AGENT_ATTRIBUTES.secret}] , input[type="password"], input[autocomplete="one-time-code"]`;

const FIELD_SELECTOR = 'input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select, [role="combobox"], [role="switch"], [role="checkbox"], [role="radiogroup"]';

const BUTTON_SELECTOR = 'button, [role="button"], a[href]';

/** The most tabs of the page a read names. */
const MAX_PAGE_TABS = 16;

/** Column headers that name the id column (for a host table whose rows carry no `data-row-key`). */
const ID_COLUMN_LABELS = new Set(['id', '#', 'nr', 'no']);

/** The references of the newest read: reference → element. The highlighter marks by them. */
export class ScreenReferenceRegistry {
  protected elements = new Map<string, Element>();

  replace(elements: Map<string, Element>): void {
    this.elements = elements;
  }

  find(reference: string): Element | null {
    const element = this.elements.get(reference);
    return element?.isConnected ? element : null;
  }

  /** An anchor is found on the page even without a read. */
  findAnchor(reference: string): Element | null {
    if (!reference.startsWith('a.')) return null;
    const anchorName = reference.slice(2);
    for (const element of Array.from(document.querySelectorAll(`[${AGENT_ATTRIBUTES.anchor}]`))) {
      if (element.getAttribute(AGENT_ATTRIBUTES.anchor) === anchorName && !element.closest(`[${AGENT_ATTRIBUTES.skip}]`)) return element;
    }
    return null;
  }

  labelOf(reference: string): string {
    const element = this.find(reference) ?? this.findAnchor(reference);
    return element ? labelOfElement(element) : reference;
  }
}

const isRendered = (element: Element): boolean => {
  if (element.closest(SKIPPED_SELECTOR)) return false;
  const htmlElement = element as HTMLElement;
  if (typeof htmlElement.getClientRects === 'function' && htmlElement.getClientRects().length === 0) return false;
  return true;
};

const textOf = (element: Element | null | undefined): string => {
  if (!element) return '';
  const htmlElement = element as HTMLElement;
  // biome-ignore lint/performance/useDomNodeTextContent: the rendered text (no hidden parts) is what the user sees
  return oneLine(htmlElement.innerText ?? element.textContent ?? '');
};

/** The accessible label of an element: aria-label, labelledby, a `<label for>`, the wrapping label, its text. */
export const labelOfElement = (element: Element): string => {
  const ariaLabel = element.getAttribute('aria-label');
  if (ariaLabel) return oneLine(ariaLabel);
  const labelledBy = element.getAttribute('aria-labelledby');
  if (labelledBy) {
    const text = labelledBy
      .split(/\s+/)
      .map(id => textOf(document.getElementById(id)))
      .join(' ');
    if (text.trim() !== '') return oneLine(text);
  }
  const id = element.getAttribute('id');
  if (id) {
    const label = document.querySelector(`label[for="${CSS.escape(id)}"]`);
    if (label) return textOf(label);
  }
  const wrappingLabel = element.closest('label');
  if (wrappingLabel) return textOf(wrappingLabel);
  const placeholder = element.getAttribute('placeholder');
  if (placeholder) return oneLine(placeholder);
  return textOf(element).slice(0, 80);
};

/**
 * The key of a table row: `data-row-key` (the package's list rows and cards, 0.3.10); a table of the host without it
 * falls back to the visible id cell, then to the row's position.
 */
export const rowKeyOf = (row: Element, idColumnIndex: number | null, rowIndex: number): string => {
  const rowKey = row.getAttribute(AGENT_ATTRIBUTES.rowKey);
  if (rowKey) return slugOf(rowKey);
  if (idColumnIndex !== null) {
    const idText = textOf(row.children.item(idColumnIndex)).replace(/^#/, '');
    if (/^[A-Za-z0-9-]{1,40}$/.test(idText)) return idText;
  }
  return `n${rowIndex + 1}`;
};

const valueOfField = (field: Element): { value: string; kind: string } => {
  if (field instanceof HTMLInputElement) {
    if (field.type === 'checkbox' || field.type === 'radio') return { value: field.checked ? 'on' : 'off', kind: field.type };
    return { value: field.value, kind: field.type || 'text' };
  }
  if (field instanceof HTMLTextAreaElement) return { value: field.value, kind: 'text area' };
  if (field instanceof HTMLSelectElement) return { value: textOf(field.selectedOptions.item(0)), kind: 'select' };
  const role = field.getAttribute('role') ?? '';
  if (role === 'switch' || role === 'checkbox') return { value: field.getAttribute('aria-checked') === 'true' ? 'on' : 'off', kind: role };
  if (role === 'radiogroup') {
    const checked = field.querySelector('[aria-checked="true"], [data-state="checked"]');
    return { value: checked ? labelOfElement(checked) : '', kind: 'choice' };
  }
  return { value: textOf(field), kind: role || 'field' };
};

const invalidMessageOf = (field: Element): string | null => {
  if (field.getAttribute('aria-invalid') !== 'true') return null;
  const describedBy = field.getAttribute('aria-describedby') ?? field.getAttribute('aria-errormessage');
  const message = (describedBy ?? '')
    .split(/\s+/)
    .map(id => textOf(document.getElementById(id)))
    .join(' ')
    .trim();
  return message !== '' ? message : 'invalid';
};

export interface DomScreenReaderOptions {
  /** The content root (the page, without the shell) */
  findRoot: () => Element | null;
  /** The current route and title */
  currentLocation: () => { route: string; title: string };
  /** Whether the open screen has unsaved changes: `no`, or `yes (<form> (<fields>))` */
  describeUnsavedChanges: () => string;
  registry: ScreenReferenceRegistry;
}

/**
 * Reads the open screen from the DOM: title and route, tabs, open dialogs, messages, then per section its fields,
 * tables and buttons. Never the agent window (`data-agent-skip`), never a secret's value.
 */
export class DomScreenReader implements ScreenReader {
  constructor(protected readonly options: DomScreenReaderOptions) {}

  read({ focus, maxChars }: { focus?: string; maxChars: number }): ScreenSnapshot {
    const location = this.options.currentLocation();
    if (focus) {
      const focused = this.options.registry.find(focus) ?? this.options.registry.findAnchor(focus);
      if (!focused) {
        return { markdown: `${focus} is not on the screen any more. Read the screen again without focus.`, route: location.route, title: location.title, references: {} };
      }
      const value = focused.matches(SECRET_FIELD_SELECTOR) || focused.closest(`[${AGENT_ATTRIBUTES.secret}]`) ? '(hidden)' : this.readWhole(focused);
      return { markdown: joinScreenParts([`${focus} "${labelOfElement(focused)}" in full:`, value], maxChars), route: location.route, title: location.title, references: {} };
    }
    const elements = new Map<string, Element>();
    const references: Record<string, string> = {};
    const names = new ReferenceNames();
    const register = (reference: string, element: Element): string => {
      const unique = names.take(reference);
      elements.set(unique, element);
      references[unique] = labelOfElement(element);
      return unique;
    };

    const parts: string[] = [`# ${capValue(location.title, 120)}`, `Route: ${location.route}`];
    const unsaved = this.options.describeUnsavedChanges();
    parts.push(`Unsaved changes: ${unsaved}`);

    const root = this.options.findRoot();
    const dialogs = Array.from(document.querySelectorAll('[role="dialog"], [role="alertdialog"]')).filter(isRendered);
    for (const dialog of dialogs) {
      parts.push(`## Dialog "${capValue(labelOfElement(dialog), 80)}" (open)`);
      parts.push(...this.readContainer(dialog, register));
    }
    if (root) {
      // the page's own tabs only: a tab list inside a form is a field's (the language tabs of a translatable text,
      // 14 per field on a long form); its open tab shows in the field's value
      const tabs = Array.from(root.querySelectorAll('[role="tab"], nav[aria-label] a[aria-current]'))
        .filter(tab => isRendered(tab) && tab.closest('form, fieldset') === null)
        .slice(0, MAX_PAGE_TABS);
      if (tabs.length > 0) {
        const tabNames = tabs.map(tab => {
          const reference = register(`t.${slugOf(textOf(tab))}`, tab);
          const current = tab.getAttribute('aria-selected') === 'true' || tab.getAttribute('aria-current') !== null;
          return `${reference} "${capValue(textOf(tab), 40)}"${current ? ' (open)' : ''}`;
        });
        parts.push(`Tabs: ${tabNames.join(' · ')}`);
      }
      const messages = Array.from(root.querySelectorAll('[role="alert"], [role="status"]')).filter(isRendered);
      for (const message of messages) {
        const text = textOf(message);
        if (text !== '') parts.push(`Message: ${capValue(text, 200)}`);
      }
      parts.push(...this.readContainer(root, register));
    } else {
      parts.push('(no page content)');
    }
    const anchors = Array.from(document.querySelectorAll(`[${AGENT_ATTRIBUTES.anchor}]`)).filter(isRendered);
    if (anchors.length > 0) {
      parts.push(`Anchors: ${anchors.map(anchor => register(`a.${anchor.getAttribute(AGENT_ATTRIBUTES.anchor)}`, anchor)).join(' · ')}`);
    }
    this.options.registry.replace(elements);
    return { markdown: joinScreenParts(parts, maxChars), route: location.route, title: location.title, references };
  }

  protected readWhole(element: Element): string {
    if (element.matches(FIELD_SELECTOR)) return valueOfField(element).value;
    // biome-ignore lint/performance/useDomNodeTextContent: the rendered text (no hidden parts) is what the user sees
    return (element as HTMLElement).innerText ?? element.textContent ?? '';
  }

  /** The sections of a container (by headings), each with its fields, tables and buttons. */
  protected readContainer(container: Element, register: (reference: string, element: Element) => string): string[] {
    const lines: string[] = [];
    const seen = new Set<Element>();
    const headings = Array.from(container.querySelectorAll('h1, h2, h3, h4, [role="heading"]')).filter(isRendered);
    for (const heading of headings) {
      const section = heading.closest('section, fieldset, [data-slot="card"], form') ?? heading.parentElement;
      const text = textOf(heading);
      if (text === '') continue;
      const reference = register(`s.${slugOf(text)}`, section ?? heading);
      const collapsed = section?.querySelector('[aria-expanded="false"]') && !section.querySelector(FIELD_SELECTOR) ? ' (closed)' : '';
      lines.push(`## ${capValue(text, 80)} (${reference})${collapsed}`);
      if (section) lines.push(...this.readElements(section, register, seen));
    }
    lines.push(...this.readElements(container, register, seen));
    return lines;
  }

  protected readElements(container: Element, register: (reference: string, element: Element) => string, seen: Set<Element>): string[] {
    const lines: string[] = [];
    for (const field of Array.from(container.querySelectorAll(FIELD_SELECTOR))) {
      if (seen.has(field) || !isRendered(field) || field.closest('[role="radiogroup"]') !== null && field.getAttribute('role') !== 'radiogroup') continue;
      seen.add(field);
      const name = field.getAttribute('name') ?? field.getAttribute('id') ?? labelOfElement(field);
      const { value, kind } = valueOfField(field);
      const facts: FieldFacts = {
        reference: register(`f.${fieldReferenceNameOf(name.includes(' ') ? slugOf(name) : name)}`, field),
        label: labelOfElement(field),
        value,
        kind,
        required: field.hasAttribute('required') || field.getAttribute('aria-required') === 'true',
        disabled: field.hasAttribute('disabled') || field.getAttribute('aria-readonly') === 'true' || field.hasAttribute('readonly'),
        invalidMessage: invalidMessageOf(field),
        isSecret: field.matches(SECRET_FIELD_SELECTOR) || field.closest(`[${AGENT_ATTRIBUTES.secret}]`) !== null,
      };
      lines.push(formatFieldLine(facts));
    }
    for (const table of Array.from(container.querySelectorAll('table, [role="grid"], [role="table"]'))) {
      if (seen.has(table) || !isRendered(table)) continue;
      seen.add(table);
      lines.push(...formatTable(this.readTable(table, register)));
    }
    // the cards of a card grid (`EntityListGrid` sets `data-row-key` on each)
    const cards = Array.from(container.querySelectorAll(`[${AGENT_ATTRIBUTES.rowKey}]`)).filter(
      card => !seen.has(card) && !card.closest('table, [role="grid"], [role="table"]') && isRendered(card)
    );
    if (cards.length > 0) {
      lines.push(`Cards: ${cards.length} on screen`);
      for (const card of cards.slice(0, MAX_TABLE_ROWS)) {
        seen.add(card);
        for (const inner of Array.from(card.querySelectorAll(BUTTON_SELECTOR))) seen.add(inner);
        lines.push(`- ${register(`row.${rowKeyOf(card, null, 0)}`, card)}: ${capValue(textOf(card), 160)}`);
      }
      if (cards.length > MAX_TABLE_ROWS) lines.push(`… ${cards.length - MAX_TABLE_ROWS} more cards on screen`);
    }
    const buttons: ButtonFacts[] = [];
    for (const button of Array.from(container.querySelectorAll(BUTTON_SELECTOR))) {
      if (seen.has(button) || !isRendered(button) || button.closest('table, [role="grid"], [role="tablist"]')) continue;
      seen.add(button);
      const label = labelOfElement(button);
      if (label === '') continue;
      buttons.push({
        reference: register(`b.${slugOf(label)}`, button),
        label,
        disabled: button.hasAttribute('disabled') || button.getAttribute('aria-disabled') === 'true',
      });
    }
    const buttonLine = formatButtons(buttons);
    if (buttonLine) lines.push(buttonLine);
    return lines;
  }

  protected readTable(table: Element, register: (reference: string, element: Element) => string): TableFacts {
    const headerCells = Array.from(table.querySelectorAll('thead th, [role="columnheader"]'));
    const columns = headerCells.map(cell => textOf(cell));
    const idColumnIndex = columns.findIndex(column => ID_COLUMN_LABELS.has(column.toLowerCase()));
    const bodyRows = Array.from(table.querySelectorAll('tbody tr, [role="row"]')).filter(
      row => row.querySelector('th[scope="col"], [role="columnheader"]') === null && isRendered(row)
    );
    const rows = bodyRows.slice(0, MAX_TABLE_ROWS).map((row, rowIndex) => ({
      reference: register(`row.${rowKeyOf(row, idColumnIndex >= 0 ? idColumnIndex : null, rowIndex)}`, row),
      cells: Array.from(row.children).map(cell => {
        const secret = cell.closest(`[${AGENT_ATTRIBUTES.secret}]`) !== null || cell.querySelector(`[${AGENT_ATTRIBUTES.secret}]`) !== null;
        return secret ? '(hidden)' : textOf(cell);
      }),
      selected: row.getAttribute('aria-selected') === 'true' || row.getAttribute('data-state') === 'selected',
    }));
    return { caption: textOf(table.querySelector('caption')) || table.getAttribute('aria-label'), columns, rows, totalRows: bodyRows.length };
  }
}
