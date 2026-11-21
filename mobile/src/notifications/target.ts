/** What a push tap is allowed to carry: two opaque uids. Anything else is ignored. */
export type NotificationTarget = { businessUid: string; conversationUid: string };

const UID = /^[A-Za-z0-9-]{8,64}$/;

export function parseNotificationTarget(data: unknown): NotificationTarget | null {
  if (!data || typeof data !== 'object') return null;
  const d = data as Record<string, unknown>;
  if (d.v !== 1 || d.t !== 'message') return null;
  if (typeof d.b !== 'string' || typeof d.c !== 'string' || !UID.test(d.b) || !UID.test(d.c)) return null;
  return { businessUid: d.b, conversationUid: d.c };
}

/** The conversation currently on screen, so a foreground push for it is not shown on top of it. */
let active: string | null = null;
export const setActiveConversation = (uid: string | null) => { active = uid; };
export const isActiveConversation = (uid: string | null) => uid !== null && active === uid;
