/**
 * Helpers for a TypeScript script that runs in the sandbox (same contract as the Python module `ddd_sandbox`).
 *
 *     import { ctx, result, inputPath, outputPath, now } from "ddd-sandbox";
 */
import { readFileSync, renameSync, writeFileSync, mkdirSync } from "node:fs";
import { basename, dirname, resolve, sep } from "node:path";

export interface SandboxBrand {
  name?: string;
  primaryColor?: string;
  secondaryColor?: string;
  [key: string]: unknown;
}

export interface SandboxContext {
  timezone: string;
  locale: string;
  brand: SandboxBrand;
  inputFiles: string[];
  now?: string;
}

const startedAt = Date.now();

function loadContext(): SandboxContext {
  const contextPath = process.env.DDD_SANDBOX_CONTEXT;
  let values: Partial<SandboxContext> = {};
  if (contextPath) {
    try {
      values = JSON.parse(readFileSync(contextPath, "utf-8"));
    } catch {
      values = {};
    }
  }
  return {
    timezone: values.timezone || "UTC",
    locale: values.locale || "",
    brand: values.brand || {},
    inputFiles: values.inputFiles || [],
    now: values.now,
  };
}

export const ctx: SandboxContext = loadContext();

/** The current moment: the moment the platform handed in plus the time this script has run. Format it in ctx.timezone. */
export function now(): Date {
  const handedIn = ctx.now ? Date.parse(ctx.now) : NaN;
  return Number.isNaN(handedIn) ? new Date() : new Date(handedIn + (Date.now() - startedAt));
}

export function inputPath(name: string): string {
  return resolve(process.env.DDD_SANDBOX_INPUT_DIRECTORY || "/workspace/in", basename(name));
}

export function outputPath(name: string): string {
  const outputDirectory = resolve(process.env.DDD_SANDBOX_OUTPUT_DIRECTORY || "/workspace/out");
  const target = resolve(outputDirectory, name);
  if (!target.startsWith(outputDirectory + sep)) {
    throw new Error("an output file must stay inside the output directory");
  }
  mkdirSync(dirname(target), { recursive: true });
  return target;
}

/** Stores the structured result of the execution (JSON). A later call replaces an earlier one. */
export function result<T>(value: T): T {
  const resultFile = process.env.DDD_SANDBOX_RESULT_FILE || "/workspace/.ddd/result.json";
  writeFileSync(resultFile + ".tmp", JSON.stringify(value));
  renameSync(resultFile + ".tmp", resultFile);
  return value;
}
