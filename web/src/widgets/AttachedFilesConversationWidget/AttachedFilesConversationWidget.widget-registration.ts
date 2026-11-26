import type { ConversationWidgetEntry } from "../../renderer/conversationWidgetRegistry";
import { AttachedFilesConversationWidget } from "./AttachedFilesConversationWidget";

/**
 * `AttachedFiles`: the files of the owner's message (a host's composer uploads them and sends the fence with
 * `buildAttachedFilesComposerWidget`). Shown above the owner's bubble.
 */
export const conversationWidgetRegistration: ConversationWidgetEntry = {
  name: "AttachedFiles",
  component: AttachedFilesConversationWidget,
  sentInCustomerMessage: true,
  customerMessagePlacement: "aboveBubble",
};
