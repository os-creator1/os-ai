import type { ConversationChange } from '../api/types';

/** What a screen reacts to: a change notice, or "you may have missed things, re-read" (reconnect / app resume). */
export type RealtimeEvent = ({ kind: 'change' } & ConversationChange) | { kind: 'resync' };

/** Minimal transport the provider needs. The production one wraps pusher-js; tests supply a fake. */
export interface RealtimeTransport {
  /** Subscribe to a private channel; `onChange` gets the event payload. Returns unsubscribe. */
  subscribe(channel: string, onChange: (payload: unknown) => void): () => void;
  /** Called every time the connection (re)establishes — the cue to resynchronise. */
  onConnected(cb: () => void): () => void;
  disconnect(): void;
}

export type TransportFactory = (cfg: import('../api/types').RealtimeConfig, authorize: (socketId: string, channel: string) => Promise<{ auth: string }>) => RealtimeTransport;

export function channelFor(businessUid: string, userUid: string): string {
  return `private-mobile.business.${businessUid}.user.${userUid}`;
}

/** Accepts only a well-formed notice; anything else is dropped, never acted on. */
export function parseChange(payload: unknown): ConversationChange | null {
  const p = typeof payload === 'string' ? safeJson(payload) : payload;
  if (!p || typeof p !== 'object') return null;
  const o = p as Record<string, unknown>;
  const types = ['received', 'outbound', 'status', 'read'];
  if (typeof o.type !== 'string' || !types.includes(o.type)) return null;
  if (typeof o.business_uid !== 'string' || typeof o.conversation_uid !== 'string') return null;
  return { type: o.type as ConversationChange['type'], business_uid: o.business_uid, conversation_uid: o.conversation_uid };
}
function safeJson(s: string): unknown { try { return JSON.parse(s); } catch { return null; } }
