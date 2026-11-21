import React from 'react';
import { AppState, Text } from 'react-native';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { RealtimeProvider } from '../src/realtime/RealtimeProvider';
import { channelFor, parseChange } from '../src/realtime/types';
import { ConversationDetailScreen } from '../src/screens/ConversationDetailScreen';
import { ConversationsScreen } from '../src/screens/ConversationsScreen';
import { isActiveConversation } from '../src/notifications/target';

jest.mock('expo-secure-store', () => {
  const m = new Map<string, string>();
  return {
    getItemAsync: async (k: string) => m.get(k) ?? null,
    setItemAsync: async (k: string, v: string) => { m.set(k, v); },
    deleteItemAsync: async (k: string) => { m.delete(k); },
  };
});
jest.mock('expo-notifications', () => ({}));
jest.mock('expo-device', () => ({ isDevice: false }));
jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { version: '0.1.0', extra: {} }, deviceName: 'Test phone' } }));

const bizA = { workspace_uid: 'ws-a', workspace_name: 'A WS', business_uid: 'biz-aaaaaaaa', name: 'Alpha', features: { conversations: true, contacts: true } };
const bizB = { workspace_uid: 'ws-b', workspace_name: 'B WS', business_uid: 'biz-bbbbbbbb', name: 'Bravo', features: { conversations: true, contacts: true } };
const me = { user: { uid: 'user-uid-0001', name: 'Pat', email: 'pat@example.test' }, businesses: [bizA, bizB] };

function fakeTransports() {
  const created: any[] = [];
  const factory = jest.fn((_cfg: any, _authorize: any) => {
    const subs = new Map<string, (p: unknown) => void>();
    let onConnected: () => void = () => undefined;
    const t = {
      subs,
      connect: () => onConnected(),
      emit: (channel: string, payload: unknown) => subs.get(channel)?.(payload),
      subscribe: jest.fn((ch: string, cb: (p: unknown) => void) => { subs.set(ch, cb); return () => { subs.delete(ch); }; }),
      onConnected: jest.fn((cb: () => void) => { onConnected = cb; return () => undefined; }),
      disconnect: jest.fn(),
    };
    created.push(t);
    return t;
  });
  return { factory, created };
}

function fakeApi(over: Record<string, any> = {}): any {
  return {
    login: jest.fn(async () => ({ token: 't', token_type: 'Bearer', expires_at: new Date(Date.now() + 1e9).toISOString(), user: me.user })),
    logout: jest.fn(async () => ({ signed_out: true })), me: jest.fn(async () => me), registerPush: jest.fn(),
    realtimeConfig: jest.fn(async () => ({ enabled: true, provider: 'pusher', key: 'k', cluster: 'mt1', host: null, port: 443, tls: true })),
    broadcastAuth: jest.fn(async () => ({ auth: 'k:sig' })),
    markRead: jest.fn(async () => ({ uid: 'c1', unread: false })),
    conversations: jest.fn(async () => ({ data: [], meta: { page: 1, next_page: null } })),
    conversation: jest.fn(async () => ({ conversation: { uid: 'c1', unread: false }, contact_uid: null, has_more: false, messages: [] })),
    reply: jest.fn(), retry: jest.fn(),
    ...over,
  };
}

function Driver({ pick }: { pick: 'A' | 'B'; }) {
  const { signIn, selectBusiness, me: m, signOut, signedIn, scope } = useAuth();
  const started = React.useRef(false);
  React.useEffect(() => { if (!started.current) { started.current = true; void signIn('pat@example.test', 'pw'); } }, [signIn]);
  const picked = React.useRef<string | null>(null);
  React.useEffect(() => { const target = m ? (pick === 'A' ? m.businesses[0] : m.businesses[1]) : null; if (target && picked.current !== pick && scope?.businessUid !== target.business_uid) { picked.current = pick; void selectBusiness(target); } }, [m, pick, scope, selectBusiness]);
  return <><Text>{signedIn ? 'in' : 'out'}</Text><Text onPress={() => void signOut()}>signout</Text></>;
}

async function mount(api: any, factory: any, child: React.ReactElement = <></>, pick: 'A' | 'B' = 'A') {
  const ui = (p: 'A' | 'B') => (
    <AuthProvider apiOverride={api}>
      <RealtimeProvider createTransport={factory}><Driver pick={p} />{child}</RealtimeProvider>
    </AuthProvider>
  );
  const view = await render(ui(pick));
  return { view, ui };
}

describe('parseChange', () => {
  it('accepts only well-formed notices', () => {
    expect(parseChange({ type: 'received', business_uid: 'b', conversation_uid: 'c' })).toEqual({ type: 'received', business_uid: 'b', conversation_uid: 'c' });
    expect(parseChange(JSON.stringify({ type: 'status', business_uid: 'b', conversation_uid: 'c' }))?.type).toBe('status');
    expect(parseChange({ type: 'evil', business_uid: 'b', conversation_uid: 'c' })).toBeNull();
    expect(parseChange({ type: 'read', business_uid: 1, conversation_uid: 'c' })).toBeNull();
    expect(parseChange('not json')).toBeNull();
    expect(parseChange(null)).toBeNull();
  });
});

describe('RealtimeProvider', () => {
  it('subscribes to this user + selected business channel, authorising through the API', async () => {
    const { factory, created } = fakeTransports();
    const api = fakeApi();
    await mount(api, factory);
    await waitFor(() => expect(created).toHaveLength(1));
    expect(created[0].subscribe).toHaveBeenCalledWith(channelFor('biz-aaaaaaaa', 'user-uid-0001'), expect.any(Function));
    const authorize = factory.mock.calls[0][1];
    await authorize('123.456', 'private-x');
    expect(api.broadcastAuth).toHaveBeenCalledWith('123.456', 'private-x');
  });

  it('does nothing when realtime is not enabled server-side', async () => {
    const { factory } = fakeTransports();
    await mount(fakeApi({ realtimeConfig: jest.fn(async () => ({ enabled: false, provider: 'pusher', key: null, cluster: null, host: null, port: null, tls: true })) }), factory);
    await waitFor(() => screen.getByText('in'));
    expect(factory).not.toHaveBeenCalled();
  });

  it('switches subscription on a Business change and releases the old one', async () => {
    const { factory, created } = fakeTransports();
    const { view, ui } = await mount(fakeApi(), factory, <></>, 'A');
    await waitFor(() => expect(created).toHaveLength(1));
    await view.rerender(ui('B'));
    await waitFor(() => expect(created).toHaveLength(2));
    expect(created[0].disconnect).toHaveBeenCalled();
    expect(created[1].subscribe).toHaveBeenCalledWith(channelFor('biz-bbbbbbbb', 'user-uid-0001'), expect.any(Function));
  });

  it('releases the subscription on sign-out', async () => {
    const { factory, created } = fakeTransports();
    await mount(fakeApi(), factory);
    await waitFor(() => expect(created).toHaveLength(1));
    await fireEvent.press(screen.getByText('signout'));
    await waitFor(() => expect(created[0].disconnect).toHaveBeenCalled());
  });

  it('survives a transport that throws (falls back to plain API behaviour)', async () => {
    const factory = jest.fn(() => { throw new Error('ws down'); });
    await mount(fakeApi(), factory);
    await waitFor(() => screen.getByText('in'));
  });
});

describe('live conversation detail', () => {
  const inbound = (id: number, body: string, uid: string | null = null) => ({ id, direction: 'inbound', type: 'plain', body, media_url: null, send_uid: uid, status: null, failure_reason: null, status_message: null, retryable: false, created_at: '2026-01-01T10:00:00Z' });
  const out = (id: number, body: string, status: string) => ({ ...inbound(id, body, 'sendu-' + id), direction: 'outbound', status });
  const detail = (messages: any[], unread = false) => ({ conversation: { uid: 'c1', unread }, contact_uid: null, has_more: false, messages });
  const open = async (api: any) => {
    const { factory, created } = fakeTransports();
    const screenEl = <ConversationDetailScreen {...({ navigation: { navigate: jest.fn(), setOptions: jest.fn() }, route: { params: { uid: 'c1', title: 'Ann' } } } as any)} />;
    await mount(api, factory, screenEl);
    await waitFor(() => screen.getByText('Hello'));
    await waitFor(() => expect(created).toHaveLength(1));
    return created[0];
  };
  const ch = channelFor('biz-aaaaaaaa', 'user-uid-0001');

  it('shows an incoming message live, once, and marks it read while open', async () => {
    const api = fakeApi({ conversation: jest.fn().mockResolvedValueOnce(detail([inbound(1, 'Hello')])).mockResolvedValue(detail([inbound(1, 'Hello'), inbound(2, 'Are you open?')], true)) });
    const t = await open(api);
    await act(async () => { t.emit(ch, { type: 'received', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' }); });
    await waitFor(() => screen.getByText('Are you open?'));
    // The same event delivered again (reconnect replay) never duplicates a bubble.
    await act(async () => { t.emit(ch, { type: 'received', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' }); });
    await waitFor(() => expect(api.conversation.mock.calls.length).toBeGreaterThanOrEqual(3));
    expect(screen.getAllByText('Are you open?')).toHaveLength(1);
    expect(api.markRead).toHaveBeenCalled();
  });

  it('ignores events for another conversation or another business', async () => {
    const api = fakeApi({ conversation: jest.fn(async () => detail([inbound(1, 'Hello')])) });
    const t = await open(api);
    const before = api.conversation.mock.calls.length;
    await act(async () => {
      t.emit(ch, { type: 'received', business_uid: 'biz-aaaaaaaa', conversation_uid: 'someone-else' });
      t.emit(ch, { type: 'received', business_uid: 'biz-bbbbbbbb', conversation_uid: 'c1' });
      t.emit(ch, { garbage: true });
    });
    expect(api.conversation.mock.calls.length).toBe(before);
  });

  it('updates a delivery state live (sent -> delivered)', async () => {
    const api = fakeApi({ conversation: jest.fn().mockResolvedValueOnce(detail([inbound(1, 'Hello'), out(2, 'On my way', 'sent')])).mockResolvedValue(detail([inbound(1, 'Hello'), out(2, 'On my way', 'delivered')])) });
    const t = await open(api);
    await waitFor(() => screen.getByText(/✓ Sent/));
    await act(async () => { t.emit(ch, { type: 'status', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' }); });
    await waitFor(() => screen.getByText(/✓✓ Delivered/));
  });

  it('re-reads after a reconnect and after returning to the foreground, recovering missed messages', async () => {
    let appHandler: (s: string) => void = () => undefined;
    jest.spyOn(AppState, 'addEventListener').mockImplementation(((_: string, h: (s: any) => void) => { appHandler = h; return { remove: jest.fn() }; }) as any);
    const api = fakeApi({ conversation: jest.fn().mockResolvedValueOnce(detail([inbound(1, 'Hello')])).mockResolvedValueOnce(detail([inbound(1, 'Hello'), inbound(2, 'Missed while offline')])).mockResolvedValue(detail([inbound(1, 'Hello'), inbound(2, 'Missed while offline'), inbound(3, 'Sent while backgrounded')])) });
    const t = await open(api);
    await act(async () => { t.connect(); });
    await waitFor(() => screen.getByText('Missed while offline'));
    await act(async () => { appHandler('active'); });
    await waitFor(() => screen.getByText('Sent while backgrounded'));
    jest.restoreAllMocks();
  });
});

describe('live conversation detail: window and focus', () => {
  const msg = (id: number, body: string) => ({ id, direction: 'inbound', type: 'plain', body, media_url: null, send_uid: null, status: null, failure_reason: null, status_message: null, retryable: false, created_at: '2026-01-01T10:00:00Z' });
  const detail = (messages: any[], unread = false, hasMore = false) => ({ conversation: { uid: 'c1', unread }, contact_uid: null, has_more: hasMore, messages });
  const ch = channelFor('biz-aaaaaaaa', 'user-uid-0001');
  const evt = { type: 'received', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' };

  function nav() {
    const handlers: Record<string, () => void> = {};
    return {
      handlers,
      navigate: jest.fn(), setOptions: jest.fn(),
      isFocused: jest.fn(() => true),
      addListener: jest.fn((name: string, cb: () => void) => { handlers[name] = cb; return () => { delete handlers[name]; }; }),
    };
  }
  async function open(api: any, navigation: any) {
    const { factory, created } = fakeTransports();
    await mount(api, factory, <ConversationDetailScreen {...({ navigation, route: { params: { uid: 'c1', title: 'Ann' } } } as any)} />);
    await waitFor(() => screen.getByText('m1'));
    await waitFor(() => expect(created).toHaveLength(1));
    return created[0];
  }

  it('keeps messages that slide out of the latest window (no gap as a thread grows)', async () => {
    const api = fakeApi({ conversation: jest.fn()
      .mockResolvedValueOnce(detail([msg(1, 'm1'), msg(2, 'm2'), msg(3, 'm3')]))
      .mockResolvedValue(detail([msg(3, 'm3'), msg(4, 'm4'), msg(5, 'm5')])) });
    const t = await open(api, nav());
    await act(async () => { t.emit(ch, evt); });
    await waitFor(() => screen.getByText('m5'));
    for (const body of ['m1', 'm2', 'm3', 'm4', 'm5']) expect(screen.getAllByText(body)).toHaveLength(1);
  });

  it('does not mark messages read, or suppress banners, while another screen covers the conversation', async () => {
    const navigation = nav();
    const api = fakeApi({ conversation: jest.fn().mockResolvedValueOnce(detail([msg(1, 'm1')])).mockResolvedValue(detail([msg(1, 'm1'), msg(2, 'm2')], true)) });
    const t = await open(api, navigation);
    expect(isActiveConversation('c1')).toBe(true);

    await act(async () => { navigation.handlers.blur(); });
    expect(isActiveConversation('c1')).toBe(false);
    await act(async () => { t.emit(ch, evt); });
    await waitFor(() => screen.getByText('m2')); // the data stays fresh underneath
    expect(api.markRead).not.toHaveBeenCalled();

    // Coming back to the conversation reads it once.
    await act(async () => { navigation.handlers.focus(); });
    expect(isActiveConversation('c1')).toBe(true);
    await waitFor(() => expect(api.markRead).toHaveBeenCalledTimes(1));
  });
});

describe('live conversation list', () => {
  const row = (uid: string, name: string, unread: boolean, preview = '') => ({ uid, display_name: name, phone: '+1555', unread, pinned: false, last_message: { direction: 'inbound', preview, at: null }, updated_at: null });
  it('moves the updated conversation to the top, shows its unread badge and never duplicates a row', async () => {
    jest.useFakeTimers();
    const conversations = jest.fn()
      .mockResolvedValueOnce({ data: [row('c1', 'Ann', false, 'old'), row('c2', 'Bo', false, 'older')], meta: { page: 1, next_page: null } })
      .mockResolvedValue({ data: [row('c2', 'Bo', true, 'brand new'), row('c1', 'Ann', false, 'old')], meta: { page: 1, next_page: null } });
    const { factory, created } = fakeTransports();
    await mount(fakeApi({ conversations }), factory, <ConversationsScreen {...({ navigation: { navigate: jest.fn(), setOptions: jest.fn() }, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann'));
    await waitFor(() => expect(created).toHaveLength(1));
    await act(async () => { created[0].emit(channelFor('biz-aaaaaaaa', 'user-uid-0001'), { type: 'received', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c2' }); });
    await act(async () => { jest.advanceTimersByTime(400); });
    await waitFor(() => screen.getByText('brand new'));
    expect(screen.getByLabelText('Unread')).toBeTruthy();
    expect(screen.getAllByText('Bo')).toHaveLength(1);
    expect(screen.getAllByText('Ann')).toHaveLength(1);
    jest.useRealTimers();
  });
});
