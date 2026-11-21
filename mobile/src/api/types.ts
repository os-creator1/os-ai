export type Business = {
  workspace_uid: string;
  workspace_name: string;
  business_uid: string;
  name: string;
  features: { conversations: boolean; contacts: boolean };
};
export type Me = { user: { uid: string; name: string; email: string }; businesses: Business[] };
export type LoginResult = { token: string; token_type: 'Bearer'; expires_at: string; user: Me['user'] };
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
export type Message = { id: number; direction: Direction; type: string; body: string | null; media_url: string | null; created_at: string };
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
