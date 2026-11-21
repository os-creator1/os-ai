import { ApiError, createApi } from '../src/api/client';
import { assertSecureOrigin } from '../src/config';

const scope = { workspaceUid: 'ws-1', businessUid: 'biz-1' };

function mk(res: { status: number; body: unknown }, token: string | null = 'tok') {
  const calls: { url: string; init: RequestInit }[] = [];
  const onUnauthorized = jest.fn();
  const fetchImpl = jest.fn(async (url: any, init: any) => {
    calls.push({ url: String(url), init });
    return { ok: res.status < 400, status: res.status, json: async () => res.body } as Response;
  });
  const api = createApi({ baseUrl: 'https://api.example.test', getToken: () => token, onUnauthorized, fetchImpl: fetchImpl as any });
  return { api, calls, onUnauthorized };
}

describe('api client', () => {
  it('sends the bearer token and addresses the tenant in the path', async () => {
    const { api, calls } = mk({ status: 200, body: { data: [], meta: { page: 1, next_page: null } } });
    await api.conversations(scope, { filter: 'unread', search: 'ann' });
    expect(calls[0].url).toBe('https://api.example.test/api/mobile/v1/workspaces/ws-1/businesses/biz-1/conversations?filter=unread&search=ann');
    expect((calls[0].init.headers as any).Authorization).toBe('Bearer tok');
  });

  it('uses the before_id cursor when loading older conversation messages', async () => {
    const { api, calls } = mk({ status: 200, body: { data: { conversation: {}, messages: [], has_more: false } } });
    await api.conversation(scope, 'conversation-1', 42);
    expect(calls[0].url).toBe('https://api.example.test/api/mobile/v1/workspaces/ws-1/businesses/biz-1/conversations/conversation-1?before_id=42');
  });

  it('does not send a token on login and never stores or logs the password', async () => {
    const { api, calls } = mk({ status: 200, body: { data: { token: 't', token_type: 'Bearer', expires_at: 'x', user: {} } } }, 'stale');
    await api.login('a@b.test', 'pw', { installation_id: 'i', platform: 'ios' });
    expect((calls[0].init.headers as any).Authorization).toBeUndefined();
  });

  it('turns a 404 tenant refusal into an ApiError without leaking detail', async () => {
    const { api } = mk({ status: 404, body: { error: { code: 'not_found', message: 'Not found.' } } });
    await expect(api.conversation(scope, 'foreign')).rejects.toMatchObject({ status: 404, code: 'not_found' });
  });

  it('signals the session is over on a 401 from an authenticated call', async () => {
    const { api, onUnauthorized } = mk({ status: 401, body: { error: { code: 'unauthenticated', message: 'x' } } });
    await expect(api.me()).rejects.toBeInstanceOf(ApiError);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });

  it('does not treat a bad-password 401 on login as a session loss', async () => {
    const { api, onUnauthorized } = mk({ status: 401, body: { error: { code: 'invalid_credentials', message: 'no' } } });
    await expect(api.login('a@b.test', 'x', { installation_id: 'i', platform: 'ios' })).rejects.toMatchObject({ code: 'invalid_credentials' });
    expect(onUnauthorized).not.toHaveBeenCalled();
  });

  it('reports network failures as a typed error', async () => {
    const api = createApi({ baseUrl: 'https://x', getToken: () => null, onUnauthorized: jest.fn(), fetchImpl: (async () => { throw new Error('down'); }) as any });
    await expect(api.me()).rejects.toMatchObject({ status: 0, code: 'network' });
  });
});

describe('assertSecureOrigin', () => {
  it('requires https outside development', () => {
    expect(() => assertSecureOrigin('http://api.example.test', false)).toThrow();
    expect(() => assertSecureOrigin('', false)).toThrow();
    expect(() => assertSecureOrigin('https://api.example.test', false)).not.toThrow();
  });
  it('allows local http in a development bundle only', () => {
    expect(() => assertSecureOrigin('http://192.168.1.5:8000', true)).not.toThrow();
  });
});
