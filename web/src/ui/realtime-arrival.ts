import type { ClientToolAnswerSender } from "./ClientToolRequestRunner";
import type { ClientToolAnswer, ClientToolRequestDelivery, ClientToolRequestType } from "./client-tools";

/** The message type of a client tool request on the realtime port (`AgentUIRealtimeMessageTypeProviderService`). */
export const CLIENT_TOOL_REQUEST_MESSAGE_TYPE = 'agent.ui.clientToolRequest';

/** A message of the realtime port as the run-state read or the host's transport delivers it (`AgentRealtimeMessageDelivery`). */
export interface AgentRealtimeMessageDelivery {
  messageType: string;
  messageKey: string;
  /** One JSON object */
  payloadJson: string;
  secondsLeft: number;
}

/** What a client needs to listen on the host's transport (`AgentRealtimeListenerCredential`). */
export interface AgentRealtimeListenerCredential {
  transportName: string;
  credential: string;
  listenPath?: string | null;
  expiresAt?: string | null;
}

/** The client tool request a realtime message carries, or null for another type or a payload that does not parse. */
export const readClientToolRequestOfRealtimeMessage = (message: AgentRealtimeMessageDelivery): ClientToolRequestDelivery | null => {
  if (message.messageType !== CLIENT_TOOL_REQUEST_MESSAGE_TYPE) return null;
  try {
    const payload: unknown = JSON.parse(message.payloadJson);
    if (typeof payload !== 'object' || payload === null) return null;
    const { id, requestType, argumentsJson, secondsLeft } = payload as Record<string, unknown>;
    if (typeof id !== 'number' || !Number.isSafeInteger(id) || typeof requestType !== 'string') return null;
    return {
      id,
      requestType,
      argumentsJson: typeof argumentsJson === 'string' ? argumentsJson : '{}',
      // the transport's own clock: the message's seconds left win when the payload's are older
      secondsLeft: Math.min(typeof secondsLeft === 'number' ? secondsLeft : message.secondsLeft, message.secondsLeft),
    };
  } catch {
    return null;
  }
};

/** What takes an arrived client tool request (an `AgentUIClient`). */
export interface ClientToolRequestAcceptor {
  acceptClientToolRequest(conversationId: number, delivery: ClientToolRequestDelivery, sendAnswer: ClientToolAnswerSender): void;
}

/**
 * The arrival adapter for a host transport: hand it every message the transport receives for a conversation; a
 * client tool request goes to the UI client (which runs a request id once, so the run-state read delivering the same
 * request is harmless), any other type to `onOtherMessage`. Returns whether the message was a client tool request.
 */
export const acceptArrivedRealtimeMessage = (
  conversationId: number,
  message: AgentRealtimeMessageDelivery,
  acceptor: ClientToolRequestAcceptor,
  sendAnswer: ClientToolAnswerSender,
  onOtherMessage?: (conversationId: number, message: AgentRealtimeMessageDelivery) => void,
): boolean => {
  const delivery = readClientToolRequestOfRealtimeMessage(message);
  if (delivery) {
    acceptor.acceptClientToolRequest(conversationId, delivery, sendAnswer);
    return true;
  }
  onOtherMessage?.(conversationId, message);
  return false;
};

/** The pause before the one re-read of `readRunStateAfterClientToolRequestPing` when the first read found nothing. */
export const CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS = 400;

/** What counts the client tool requests delivered to the UI client (`AgentUIClient`). */
export interface ClientToolRequestDeliveryCounter {
  countAcceptedClientToolRequests(): number;
}

/**
 * The arrival adapter for a host transport that only pings (it carries no message, e.g. the per-conversation ping of
 * a client tool request): reads the run state, which hands a waiting request to the UI client; when that read
 * delivered none — the ping overtook the server's write — it reads once more after `rereadDelayMs`, so the request is
 * not left to the next regular read. Resolves with whether a request was delivered.
 */
export const readRunStateAfterClientToolRequestPing = async (
  conversationId: number,
  readRunState: (conversationId: number) => Promise<unknown>,
  deliveryCounter: ClientToolRequestDeliveryCounter,
  rereadDelayMs: number = CLIENT_TOOL_REQUEST_PING_REREAD_DELAY_MS,
): Promise<boolean> => {
  const acceptedBefore = deliveryCounter.countAcceptedClientToolRequests();
  await readRunState(conversationId);
  if (deliveryCounter.countAcceptedClientToolRequests() > acceptedBefore) return true;
  await new Promise<void>(resolve => setTimeout(resolve, rereadDelayMs));
  await readRunState(conversationId);
  return deliveryCounter.countAcceptedClientToolRequests() > acceptedBefore;
};

let developmentRequestId = 9_000_000_000_000;

/**
 * The dev helper: runs a request through the UI client as if the server had sent it and resolves with the answer, so
 * an application tries its adapters without a model (e.g. from the browser console in development).
 */
export const runClientToolRequestForDevelopment = (
  acceptor: ClientToolRequestAcceptor,
  requestType: ClientToolRequestType | string,
  parameters: Record<string, unknown> = {},
  secondsLeft: number = 30,
): Promise<ClientToolAnswer> =>
  new Promise(resolve => {
    developmentRequestId += 1;
    acceptor.acceptClientToolRequest(
      0,
      { id: developmentRequestId, requestType, argumentsJson: JSON.stringify(parameters), secondsLeft },
      async (_requestId, answer) => resolve(answer),
    );
  });
