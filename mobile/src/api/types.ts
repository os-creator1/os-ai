export type Business = {
  workspace_uid: string;
  workspace_name: string;
  business_uid: string;
  name: string;
  features: { conversations: boolean; contacts: boolean };
};
export type Me = { user: { uid: string; name: string; email: string }; businesses: Business[] };
export type LoginResult = { token: string; token_type: 'Bearer'; expires_at: string; user: Me['user'] };
/** A correct password for a two-factor account: no token yet, only a short-lived, single-use challenge. */
export type TwoFactorChallenge = { two_factor_required: true; challenge: string; expires_at: string; methods: TwoFactorMethod[] };
export type TwoFactorMethod = 'code' | 'backup_code';
export type Direction = 'inbound' | 'outbound';
export type ConversationSummary = {
  uid: string;
  display_name: string | null;
  phone: string | null;
  unread: boolean;
  pinned: boolean;
  last_message: { direction: Direction; preview: string; at: string | null } | null;
  updated_at: string | null;
};
export type SendStatus = 'sending' | 'sent' | 'delivered' | 'failed' | 'delivery_failed' | 'ambiguous';
export type Message = {
  id: number;
  direction: Direction;
  type: string;
  body: string | null;
  media_url: string | null;
  send_uid: string | null;
  /** Delivery state of a tracked outgoing message; null for inbound or untracked messages. */
  status: SendStatus | null;
  failure_reason: string | null;
  status_message: string | null;
  retryable: boolean;
  created_at: string;
};
export type SendResult = { message: Partial<Message> & { send_uid: string; status: SendStatus } };
export type ConversationDetail = { conversation: ConversationSummary; contact_uid: string | null; messages: Message[]; has_more: boolean };
export type ContactProfile = {
  uid: string;
  display_name: string | null;
  phone: string;
  email: string | null;
  subscribed: boolean;
  fields: { tag: string; label: string; value: string | null }[];
  created_at: string | null;
};
export type CallingCapability = { available: boolean; reason: string | null; phase: string };
export type Scope = { workspaceUid: string; businessUid: string };

export type RealtimeConfig = {
  enabled: boolean;
  provider: 'pusher';
  key: string | null;
  cluster: string | null;
  host: string | null;
  port: number | null;
  tls: boolean;
};
/** Content-free change notice. The app re-reads through the API; it never trusts these fields for anything but "what to refetch". */
export type ChangeType = 'received' | 'outbound' | 'status' | 'read';
export type ConversationChange = { type: ChangeType; business_uid: string; conversation_uid: string };
