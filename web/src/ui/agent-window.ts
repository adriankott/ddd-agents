import type { AgentWindowAdapter, ClientToolRequestType } from "./client-tools";

export interface AgentWindowAdapterOptions {
  /** The mode the window shows now */
  getMode: () => string;
  /** Shows a mode; called only with a mode of `modes` */
  applyMode: (mode: string) => void | Promise<void>;
  /** The modes the application knows (`setAgentWindowMode` refuses others) */
  modes: string[];
  /** The mode of `stepAside()`; default `aside` */
  asideMode?: string;
  /** The request types the window steps aside for; default `OPEN_TARGET`, `HIGHLIGHT`, a picture (`READ_SCREEN`) */
  stepAsideFor?: ClientToolRequestType[];
}

/**
 * An `AgentWindowAdapter` over the application's own window state: `stepAside()` remembers the mode and shows the
 * aside mode, `restore()` goes back to the remembered mode, `setMode()` shows a known mode. Stepping aside before the
 * agent opens a screen, marks elements or takes a picture is the default.
 */
export const createAgentWindowAdapter = (options: AgentWindowAdapterOptions): AgentWindowAdapter => {
  const asideMode = options.asideMode ?? 'aside';
  const stepAsideFor = options.stepAsideFor ?? ['OPEN_TARGET', 'HIGHLIGHT', 'READ_SCREEN'];
  let modeBeforeAside: string | null = null;
  return {
    stepAside: async () => {
      const currentMode = options.getMode();
      if (currentMode === asideMode) return;
      modeBeforeAside = currentMode;
      await options.applyMode(asideMode);
    },
    restore: async () => {
      if (modeBeforeAside === null) return;
      const mode = modeBeforeAside;
      modeBeforeAside = null;
      await options.applyMode(mode);
    },
    setMode: async (mode: string) => {
      if (!options.modes.includes(mode)) return false;
      modeBeforeAside = null;
      await options.applyMode(mode);
      return true;
    },
    shouldStepAsideBefore: (requestType: ClientToolRequestType) => stepAsideFor.includes(requestType),
    getMode: () => options.getMode(),
  };
};
