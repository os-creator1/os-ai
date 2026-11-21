import type {
  CallingCapability, ContactProfile, ConversationDetail, ConversationSummary, LoginResult, Me, Scope, SendResult,
} from './types';

export class ApiError extends Error {
  constructor(
    public status: number,
    public code: string,
    message: string,
    /** Server says a deliberate Retry is meaningful (a failed message that was recorded). */
    public retryable: boolean = false,
    public sendUid: string | null = null,
  ) {
    super(message);
  }
}

export type DeviceInfo = { installation_id: string; platform: 'ios' | 'android'; name?: string; app_version?: string };

type Deps = {
  baseUrl: string;
  getToken: () => string | null;
  /** Called on any 401 from an authenticated call: the token is gone, so the session is over. */
  onUnauthorized: () => void;
  fetchImpl?: typeof fetch;
};

/** Thin typed client for /api/mobile/v1. Every call is bounded by a timeout; errors are ApiError. */
export function createApi({ baseUrl, getToken, onUnauthorized, fetchImpl }: Deps) {
  const f = fetchImpl ?? fetch;

  async function call<T>(method: string, path: string, body?: unknown, auth = true, raw = false): Promise<T> {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 20000);
    const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': 'application/json' };
    const token = getToken();
    if (auth && token) headers.Authorization = `Bearer ${token}`;
    try {
      const res = await f(`${baseUrl}/api/mobile/v1/${path}`, {
        method, headers, body: body === undefined ? undefined : JSON.stringify(body), signal: ctrl.signal,
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok) {
        if (res.status === 401 && auth) onUnauthorized();
        throw new ApiError(res.status, json?.error?.code ?? 'error', json?.error?.message ?? 'Request failed', json?.error?.retryable === true, json?.error?.send_uid ?? null);
      }
      return raw || json.data === undefined ? json : json.data;
    } catch (e) {
      if (e instanceof ApiError) throw e;
      throw new ApiError(0, 'network', 'Could not reach the server.');
    } finally {
      clearTimeout(timer);
    }
  }

  const base = (s: Scope) => `workspaces/${encodeURIComponent(s.workspaceUid)}/businesses/${encodeURIComponent(s.businessUid)}`;

  return {
    login: (email: string, password: string, device: DeviceInfo) => call<LoginResult>('POST', 'auth/login', { email, password, device }, false),
    logout: () => call<{ signed_out: boolean }>('POST', 'auth/logout'),
    refresh: () => call<LoginResult>('POST', 'auth/refresh'),
    me: () => call<Me>('GET', 'businesses'),
    registerPush: (push_provider: 'expo' | 'fcm' | 'apns', push_token: string, app_version?: string) =>
      call<{ push_registered: boolean }>('PUT', 'devices/current', { push_provider, push_token, app_version }),
    unregisterPush: () => call<{ push_registered: boolean }>('DELETE', 'devices/current'),
    conversations: async (s: Scope, q: { filter?: 'all' | 'unread' | 'read'; search?: string; page?: number } = {}) => {
      const p = new URLSearchParams();
      if (q.filter) p.set('filter', q.filter);
      if (q.search) p.set('search', q.search);
      if (q.page) p.set('page', String(q.page));
      return call<{ data: ConversationSummary[]; meta: { page: number; next_page: number | null } }>('GET', `${base(s)}/conversations?${p}`, undefined, true, true);
    },
    conversation: (s: Scope, uid: string, beforeId?: number) => call<ConversationDetail>('GET', `${base(s)}/conversations/${encodeURIComponent(uid)}${beforeId === undefined ? '' : `?before_id=${beforeId}`}`),
    markRead: (s: Scope, uid: string) => call<{ uid: string; unread: boolean }>('POST', `${base(s)}/conversations/${encodeURIComponent(uid)}/read`),
    /** `idempotencyKey` identifies ONE logical message: resending the same key can never produce a second SMS. */
    reply: (s: Scope, uid: string, body: string, idempotencyKey: string) =>
      call<SendResult>('POST', `${base(s)}/conversations/${encodeURIComponent(uid)}/reply`, { body, idempotency_key: idempotencyKey }),
    retry: (s: Scope, uid: string, sendUid: string) =>
      call<SendResult>('POST', `${base(s)}/conversations/${encodeURIComponent(uid)}/retry`, { send_uid: sendUid }),
    contact: (s: Scope, uid: string) => call<ContactProfile>('GET', `${base(s)}/contacts/${encodeURIComponent(uid)}`),
    callingCapability: (s: Scope) => call<CallingCapability>('GET', `${base(s)}/calls/capability`),
  };
}
export type Api = ReturnType<typeof createApi>;
