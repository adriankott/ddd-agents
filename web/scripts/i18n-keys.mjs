#!/usr/bin/env node
// The manifest of the package's text keys (`src/i18n-keys.json`, published as `@mgamadeus/ddd-agents/i18n-keys.json`):
// every interface text the package hands to the translate function (`AgentsEnvironment.texts.translate`), with its
// English default, its `%placeholders%`, the plural contexts it is asked in and the source files that use it. The key is
// the English source text, so the default text is the key with its placeholders substituted.
//
// A key is found where the package translates it:
//   - the first argument of `translateText(…)`, `….translate(…)`, `useTranslatedText(…)` and the marker `textKey(…)`;
//   - a string child of the translating components (`Text`, `WidgetText`, `Button`, `StatusChip`, `DonePill`,
//     `ConversationWidgetButton`) unless the element
//     says `translate={false}`, and the text props of the others (`Tooltip.text`, `StatusBadge.text`, the `label` of
//     `Checkbox`, `TextInput` and `TextArea`);
//   - where such an argument, child or prop is a variable, the strings its declaration, its assignments or its
//     destructuring default hold (a name destructured from an object: the properties of that name in the file): a
//     literal, both sides of a condition, the elements of an array.
// A text that reaches the translate function another way is wrapped in `textKey("…")` (`src/provider/text-key.ts`).
//
// Usage: node scripts/i18n-keys.mjs           (prints the manifest)
//        node scripts/i18n-keys.mjs --write   (writes src/i18n-keys.json)
//        node scripts/i18n-keys.mjs --check   (fails when src/i18n-keys.json is not what the sources give)
import * as fs from "node:fs";
import * as path from "node:path";
import { fileURLToPath } from "node:url";
import { parseAst, transformWithEsbuild } from "vite";

const packageDir = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
export const I18N_KEYS_FILE = path.join(packageDir, "src", "i18n-keys.json");

const JSX_FACTORY = "__agentsJsx";
const TRANSLATE_FUNCTIONS = new Set(["translateText", "useTranslatedText", "textKey"]);
/** Components whose string children are text keys. */
const CHILD_TRANSLATING_COMPONENTS = new Set(["Text", "WidgetText", "Button", "StatusChip", "ConversationWidgetButton", "DonePill"]);
/** Components whose named props are text keys. */
const PROP_TRANSLATING_COMPONENTS = {
  Tooltip: ["text"],
  StatusBadge: ["text"],
  Checkbox: ["label"],
  TextInput: ["label"],
  TextArea: ["label"],
};
const SKIPPED_FILE = /(?:^|[./_-])(?:spec|test|stories|story)\.|spec-helper|story-helper|\.d\.ts$|\/client\/testing\//;
const RESOLVE_DEPTH = 4;

/** Every source file below `src` that ships code. */
const readSourceFiles = (sourceDir) =>
  fs
    .readdirSync(sourceDir, { recursive: true })
    .map(String)
    .filter((file) => /\.(ts|tsx)$/.test(file))
    .map((file) => file.split(path.sep).join("/"))
    .filter((file) => !SKIPPED_FILE.test(`/${file}`))
    .sort();

/** Calls `visit` for every node of an ESTree tree. */
const walk = (node, visit) => {
  if (!node || typeof node.type !== "string") return;
  visit(node);
  for (const [key, value] of Object.entries(node)) {
    if (key === "type" || key === "start" || key === "end") continue;
    if (Array.isArray(value)) for (const child of value) walk(child, visit);
    else if (value && typeof value === "object" && typeof value.type === "string") walk(value, visit);
  }
};

const isStringLiteral = (node) => node?.type === "Literal" && typeof node.value === "string";

/** A text key worth listing: it has a letter. */
const isTextLike = (text) => /\p{L}/u.test(text);

/** A string a variable resolved to: a sentence or a word, not an identifier-like token (a role, a tone, a class). */
const isResolvedTextLike = (text) => isTextLike(text) && (/\s/.test(text) || /^\p{Lu}/u.test(text) || /[.…!?:]$/.test(text));

const readPropertyName = (property) =>
  property.key?.type === "Identifier" ? property.key.name : isStringLiteral(property.key) ? property.key.value : undefined;

/** The props object of a JSX call, as `name → value node`. */
const readJsxProps = (propsNode) => {
  const props = new Map();
  if (propsNode?.type !== "ObjectExpression") return props;
  for (const property of propsNode.properties) {
    if (property.type !== "Property") continue;
    const name = readPropertyName(property);
    if (name !== undefined) props.set(name, property.value);
  }
  return props;
};

/** One file: its declarations by name (for resolving a variable) and its keys. */
const extractKeysOfFile = (ast) => {
  const declarationsByName = new Map();
  const addDeclaration = (name, node) => {
    if (!node) return;
    if (!declarationsByName.has(name)) declarationsByName.set(name, []);
    declarationsByName.get(name).push(node);
  };
  // A name destructured from an object (`const { title } = useMemo(() => ({ title: … }))`) resolves to the values of
  // the object properties of the same name in the file.
  const destructuredNames = new Set();
  const propertyValuesByName = new Map();
  walk(ast, (node) => {
    if (node.type === "ObjectPattern") {
      for (const property of node.properties) {
        const target = property.value?.type === "AssignmentPattern" ? property.value.left : property.value;
        if (target?.type === "Identifier") destructuredNames.add(target.name);
      }
    }
    if (node.type === "ObjectExpression") {
      for (const property of node.properties) {
        const name = property.type === "Property" && !property.computed ? readPropertyName(property) : undefined;
        if (name === undefined) continue;
        if (!propertyValuesByName.has(name)) propertyValuesByName.set(name, []);
        propertyValuesByName.get(name).push(property.value);
      }
    }
  });
  walk(ast, (node) => {
    if (node.type === "VariableDeclarator" && node.id?.type === "Identifier") addDeclaration(node.id.name, node.init);
    if (node.type === "AssignmentPattern" && node.left?.type === "Identifier") addDeclaration(node.left.name, node.right);
    if (node.type === "AssignmentExpression" && node.operator === "=" && node.left?.type === "Identifier") {
      addDeclaration(node.left.name, node.right);
    }
  });

  const found = [];
  /** The strings an expression can be, following variables. */
  const collectStrings = (node, isResolved, depth, sink) => {
    if (!node || depth > RESOLVE_DEPTH) return;
    if (isStringLiteral(node)) {
      if (isResolved ? isResolvedTextLike(node.value) : isTextLike(node.value)) sink.push(node.value);
      return;
    }
    if (node.type === "TemplateLiteral" && node.expressions.length === 0) {
      collectStrings({ type: "Literal", value: node.quasis[0].value.cooked }, isResolved, depth, sink);
      return;
    }
    if (node.type === "ConditionalExpression") {
      collectStrings(node.consequent, isResolved, depth, sink);
      collectStrings(node.alternate, isResolved, depth, sink);
      return;
    }
    if (node.type === "LogicalExpression") {
      collectStrings(node.left, isResolved, depth, sink);
      collectStrings(node.right, isResolved, depth, sink);
      return;
    }
    if (node.type === "ArrayExpression") {
      for (const element of node.elements) collectStrings(element, isResolved, depth, sink);
      return;
    }
    if (node.type === "CallExpression" && node.callee.type === "Identifier" && node.callee.name === "textKey") {
      collectStrings(node.arguments[0], false, depth, sink);
      return;
    }
    if (node.type === "MemberExpression" && node.computed) {
      collectStrings(node.object, isResolved, depth, sink);
      return;
    }
    if (node.type === "Identifier") {
      for (const declaration of declarationsByName.get(node.name) ?? []) collectStrings(declaration, true, depth + 1, sink);
      if (destructuredNames.has(node.name)) {
        for (const propertyValue of propertyValuesByName.get(node.name) ?? []) collectStrings(propertyValue, true, depth + 1, sink);
      }
    }
  };
  const readContexts = (node) => {
    const contexts = [];
    collectStrings(node, false, 0, contexts);
    return contexts;
  };
  const addKeys = (valueNode, contextNode) => {
    const keys = [];
    collectStrings(valueNode, false, 0, keys);
    const contexts = contextNode ? readContexts(contextNode) : [];
    for (const key of keys) found.push({ key, contexts });
  };

  walk(ast, (node) => {
    if (node.type !== "CallExpression") return;
    const { callee } = node;
    const isTranslateCall =
      (callee.type === "Identifier" && TRANSLATE_FUNCTIONS.has(callee.name)) ||
      (callee.type === "MemberExpression" && !callee.computed && callee.property?.name === "translate");
    if (isTranslateCall) {
      // `useTranslatedText(text, translate, replacements, context)`; the others take `(key, replacements, context)`.
      const contextArgument = callee.type === "Identifier" && callee.name === "useTranslatedText" ? node.arguments[3] : node.arguments[2];
      addKeys(node.arguments[0], contextArgument);
      return;
    }
    if (callee.type !== "Identifier" || callee.name !== JSX_FACTORY) return;
    const [componentNode, propsNode, ...childNodes] = node.arguments;
    if (componentNode?.type !== "Identifier") return;
    const props = readJsxProps(propsNode);
    if (props.get("translate")?.type === "Literal" && props.get("translate").value === false) return;
    if (CHILD_TRANSLATING_COMPONENTS.has(componentNode.name)) {
      for (const childNode of childNodes) addKeys(childNode, props.get("context"));
    }
    for (const propName of PROP_TRANSLATING_COMPONENTS[componentNode.name] ?? []) {
      if (props.has(propName)) addKeys(props.get(propName), props.get("context"));
    }
  });
  return found;
};

const readPlaceholders = (key) => [...new Set([...key.matchAll(/%([A-Za-z0-9_]+)%/g)].map((match) => match[1]))].sort();

/** The manifest the sources give: one entry per key, sorted by key. */
export const buildI18nKeysManifest = async (sourceDir = path.join(packageDir, "src")) => {
  const entriesByKey = new Map();
  for (const file of readSourceFiles(sourceDir)) {
    const code = fs.readFileSync(path.join(sourceDir, file), "utf-8");
    if (!/translate|textKey|<[A-Z]/.test(code)) continue;
    const transformed = await transformWithEsbuild(code, file, {
      loader: file.endsWith(".tsx") ? "tsx" : "ts",
      jsx: "transform",
      jsxFactory: JSX_FACTORY,
      jsxFragment: `${JSX_FACTORY}Fragment`,
      target: "es2022",
      sourcemap: false,
      tsconfigRaw: { compilerOptions: { jsx: "react", jsxFactory: JSX_FACTORY } },
    });
    const ast = parseAst(transformed.code);
    for (const { key, contexts } of extractKeysOfFile(ast)) {
      const entry = entriesByKey.get(key) ?? { contexts: new Set(), usedIn: new Set() };
      for (const context of contexts) entry.contexts.add(context);
      entry.usedIn.add(`src/${file}`);
      entriesByKey.set(key, entry);
    }
  }
  const keys = [...entriesByKey.entries()]
    .sort(([firstKey], [secondKey]) => (firstKey < secondKey ? -1 : firstKey > secondKey ? 1 : 0))
    .map(([key, entry]) => ({
      key,
      defaultText: key,
      placeholders: readPlaceholders(key),
      contexts: [...entry.contexts].sort(),
      usedIn: [...entry.usedIn].sort(),
    }));
  return { keys };
};

export const serializeI18nKeysManifest = (manifest) => `${JSON.stringify(manifest, null, 2)}\n`;

const isRunDirectly = process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url);
if (isRunDirectly) {
  const manifestText = serializeI18nKeysManifest(await buildI18nKeysManifest());
  if (process.argv.includes("--write")) {
    fs.writeFileSync(I18N_KEYS_FILE, manifestText);
    console.log(`wrote ${path.relative(packageDir, I18N_KEYS_FILE)} (${JSON.parse(manifestText).keys.length} keys)`);
  } else if (process.argv.includes("--check")) {
    const committedText = fs.existsSync(I18N_KEYS_FILE) ? fs.readFileSync(I18N_KEYS_FILE, "utf-8") : "";
    if (committedText !== manifestText) {
      console.error(`${path.relative(packageDir, I18N_KEYS_FILE)} is stale: run \`pnpm i18n-keys\` and commit the result`);
      process.exit(1);
    }
    console.log(`ok      ${path.relative(packageDir, I18N_KEYS_FILE)} is fresh (${JSON.parse(manifestText).keys.length} keys)`);
  } else {
    process.stdout.write(manifestText);
  }
}
