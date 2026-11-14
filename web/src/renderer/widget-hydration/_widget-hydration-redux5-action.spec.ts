import { describe, expect, it } from "vitest";
import type { AgentsStateAction } from "../../provider/environment";
import { WidgetHydrationReducer } from "./widget-hydration.reducer";

// The shape of redux 5's `UnknownAction` (redux 5 is not a dependency of the package): a host store of redux 5 /
// react-redux 9 types every reducer it registers over this action type.
interface Redux5UnknownAction {
  type: string;
  [extraProps: string]: unknown;
}

describe("the widget hydration reducer in a redux 5 host store", () => {
  it("is a reducer over redux 5's unknown action, and the package's actions are such actions", () => {
    const reducerOverUnknownAction: (
      state: ReturnType<typeof WidgetHydrationReducer> | undefined,
      action: Redux5UnknownAction,
    ) => ReturnType<typeof WidgetHydrationReducer> = WidgetHydrationReducer;
    const packageAction: AgentsStateAction = { type: "agents/unrelated" };
    const unknownAction: Redux5UnknownAction = packageAction;

    const initialState = reducerOverUnknownAction(undefined, { type: "@@INIT" });
    expect(reducerOverUnknownAction(initialState, unknownAction)).toBe(initialState);
  });
});
