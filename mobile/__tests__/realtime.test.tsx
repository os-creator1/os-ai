import React from 'react';
import { AppState, Text } from 'react-native';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { RealtimeProvider } from '../src/realtime/RealtimeProvider';
import { channelFor, parseChange } from '../src/realtime/types';
import { ConversationDetailScreen } from '../src/screens/ConversationDetailScreen';
import { ApiError } from '../src/api/client';
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

  it('restarts from the fresh window when a reconnect window shares nothing with what was loaded (no hidden gap)', async () => {
    const api = fakeApi({ conversation: jest.fn()
      .mockResolvedValueOnce({ ...detail([msg(1, 'm1'), msg(2, 'm2')]), has_more: true })
      .mockResolvedValueOnce({ ...detail([msg(101, 'm101'), msg(102, 'm102')]), has_more: true })
      .mockResolvedValue({ ...detail([msg(51, 'm51'), msg(52, 'm52')]), has_more: true }) });
    const t = await open(api, nav());
    await act(async () => { t.emit(ch, evt); });
    await waitFor(() => screen.getByText('m102'));
    // The stale, non-contiguous history is not spliced in; "Load earlier" pages back from the fresh window.
    expect(screen.queryByText('m1')).toBeNull();
    await fireEvent.press(screen.getByText('Load earlier messages'));
    expect(api.conversation).toHaveBeenLastCalledWith(expect.anything(), 'c1', 101);
  });

  it('a slower full load cannot overwrite a newer live refresh that started after it', async () => {
    const deferred: Array<(v: any) => void> = [];
    const conversation = jest.fn()
      .mockResolvedValueOnce(detail([msg(1, 'm1')]))
      .mockImplementation(() => new Promise((r) => { deferred.push(r); }));
    const api = fakeApi({ conversation });
    const t = await open(api, nav());
    // Pull-to-refresh (full load) starts, then a realtime event starts a live refresh.
    await act(async () => { screen.getByTestId('conversation-messages').props.onRefresh(); });
    await act(async () => { t.emit(ch, evt); });
    await waitFor(() => expect(deferred).toHaveLength(2));
    await act(async () => { deferred[1](detail([msg(1, 'm1'), msg(2, 'm2')])); });   // live (newer) answers first
    await waitFor(() => screen.getByText('m2'));
    await act(async () => { deferred[0](detail([msg(1, 'm1')])); });                 // the older load answers last
    expect(screen.getByText('m2')).toBeTruthy();
  });

  it('keeps a just-sent message visible when its post-send reload is superseded by a live refresh that then fails', async () => {
    const deferred: Array<{ resolve: (v: any) => void; reject: (e: any) => void }> = [];
    const conversation = jest.fn()
      .mockResolvedValueOnce(detail([msg(1, 'm1')]))
      .mockImplementation(() => new Promise((resolve, reject) => { deferred.push({ resolve, reject }); }));
    const api = fakeApi({ conversation, reply: jest.fn(async (_s: any, _u: any, _b: any, key: string) => ({ message: { send_uid: key, status: 'sent' } })) });
    const t = await open(api, nav());
    await fireEvent.changeText(screen.getByLabelText('Message'), 'On my way');
    await fireEvent.press(screen.getByLabelText('Send'));
    await waitFor(() => expect(deferred).toHaveLength(1));               // post-send load() in flight
    await act(async () => { t.emit(ch, evt); });                          // the send's own event starts a live refresh
    await waitFor(() => expect(deferred).toHaveLength(2));
    await act(async () => { deferred[1].reject(new Error('offline')); }); // ...which fails silently
    await act(async () => { deferred[0].resolve(detail([msg(1, 'm1')])); });
    expect(screen.getByText('On my way')).toBeTruthy();                   // the bubble is not lost
  });

  it('a "Load earlier" already in flight cannot re-open a gap that a window restart just closed', async () => {
    const deferred: Array<(v: any) => void> = [];
    const conversation = jest.fn()
      .mockResolvedValueOnce({ ...detail([msg(60, 'm1'), msg(61, 'm61')]), has_more: true })
      .mockImplementation(() => new Promise((r) => { deferred.push(r); }));
    const api = fakeApi({ conversation });
    const t = await open(api, nav());
    await fireEvent.press(screen.getByText('Load earlier messages'));    // pages before 60
    await act(async () => { t.emit(ch, evt); });                          // reconnect window shares nothing with what we hold
    await waitFor(() => expect(deferred).toHaveLength(2));
    await act(async () => { deferred[1]({ ...detail([msg(201, 'm201'), msg(202, 'm202')]), has_more: true }); });
    await waitFor(() => screen.getByText('m202'));
    await act(async () => { deferred[0]({ ...detail([msg(10, 'm10')]), has_more: false }); });   // the stale page arrives late
    expect(screen.queryByText('m10')).toBeNull();
    expect(screen.getByText('Load earlier messages')).toBeTruthy();       // hasMore not clobbered
  });

  it('a superseded retry whose history a live refresh already applied is not shown as unconfirmed', async () => {
    const failed = { ...msg(1, 'm1'), direction: 'outbound', send_uid: 'su-1', status: 'delivery_failed', retryable: true };
    const sent = { ...failed, status_message: 'Carrier could not deliver.' };   // still failed on the server: the note would show
    const deferred: Array<(v: any) => void> = [];
    const conversation = jest.fn()
      .mockResolvedValueOnce(detail([failed]))
      .mockImplementation(() => new Promise((r) => { deferred.push(r); }));
    const api = fakeApi({ conversation, retry: jest.fn(async () => ({ message: { send_uid: 'su-1', status: 'sent' } })) });
    const t = await open(api, nav());
    fireEvent.press(screen.getByText('Retry'));
    await waitFor(() => expect(deferred).toHaveLength(1));              // post-retry load() in flight
    await act(async () => { t.emit(ch, evt); });                          // a live refresh starts after it
    await waitFor(() => expect(deferred).toHaveLength(2));
    await act(async () => { deferred[1](detail([sent])); });              // live applies first
    await act(async () => { deferred[0](detail([sent])); });              // the superseded load answers last
    expect(screen.queryByText(/could not refresh/)).toBeNull();
    expect(screen.getByText('Retry')).toBeTruthy();                       // and Retry is not hidden
  });

  it('does not yank the user to the bottom on a live update after they scrolled up to read history', async () => {
    const FlatList = require('react-native').FlatList;
    const spy = jest.spyOn(FlatList.prototype, 'scrollToEnd').mockImplementation(() => undefined);
    const api = fakeApi({ conversation: jest.fn().mockResolvedValueOnce(detail([msg(1, 'm1')], false, true)).mockResolvedValue(detail([msg(1, 'm1'), msg(2, 'm2')])) });
    const t = await open(api, nav());
    const list = screen.getByTestId('conversation-messages');
    // The user scrolls up (far from the bottom).
    await act(async () => { fireEvent.scroll(list, { nativeEvent: { contentOffset: { y: 0 }, contentSize: { height: 2000 }, layoutMeasurement: { height: 600 } } }); });
    spy.mockClear();
    await act(async () => { t.emit(ch, evt); });
    await waitFor(() => screen.getByText('m2'));
    await act(async () => { fireEvent(list, 'contentSizeChange', 100, 2100); });
    expect(spy).not.toHaveBeenCalled();

    // Back at the bottom: following resumes.
    await act(async () => { fireEvent.scroll(list, { nativeEvent: { contentOffset: { y: 1500 }, contentSize: { height: 2100 }, layoutMeasurement: { height: 600 } } }); });
    await act(async () => { fireEvent(list, 'contentSizeChange', 100, 2300); });
    expect(spy).toHaveBeenCalled();
    spy.mockRestore();
  });

  it('ignores a slower, older live response that finishes after a newer one (no stale overwrite)', async () => {
    const deferred: Array<(v: any) => void> = [];
    const conversation = jest.fn()
      .mockResolvedValueOnce(detail([{ ...msg(1, 'm1'), direction: 'outbound', send_uid: 'su-1', status: 'sent' }]))
      .mockImplementation(() => new Promise((r) => { deferred.push(r); }));
    const api = fakeApi({ conversation });
    const t = await open(api, nav());
    await act(async () => { t.emit(ch, { type: 'status', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' }); });
    await act(async () => { t.emit(ch, { type: 'status', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' }); });
    await waitFor(() => expect(deferred).toHaveLength(2));
    const delivered = detail([{ ...msg(1, 'm1'), direction: 'outbound', send_uid: 'su-1', status: 'delivered' }, msg(2, 'm2')]);
    const stale = detail([{ ...msg(1, 'm1'), direction: 'outbound', send_uid: 'su-1', status: 'sent' }]);
    await act(async () => { deferred[1](delivered); });   // the NEWER request answers first
    await waitFor(() => screen.getByText(/✓✓ Delivered/));
    await act(async () => { deferred[0](stale); });        // the OLDER one answers last
    expect(screen.getByText(/✓✓ Delivered/)).toBeTruthy();
    expect(screen.getByText('m2')).toBeTruthy();
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

  it('reconciles a conversation loaded on a later page when it is read elsewhere (page 1 alone would leave its badge)', async () => {
    jest.useFakeTimers();
    const conversations = jest.fn(async (_s: any, q: any) => q.page === 2
      ? { data: [row('c9', 'Older Lead', true, 'unread preview')], meta: { page: 2, next_page: null } }
      : { data: [row('c1', 'Ann', false, 'top')], meta: { page: 1, next_page: 2 } });
    const conversation = jest.fn(async () => ({ conversation: row('c9', 'Older Lead', false, 'unread preview'), contact_uid: null, has_more: false, messages: [] }));
    const { factory, created } = fakeTransports();
    await mount(fakeApi({ conversations, conversation }), factory, <ConversationsScreen {...({ navigation: { navigate: jest.fn(), setOptions: jest.fn() }, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann'));
    await fireEvent.press(screen.getByText('Load older leads'));
    await waitFor(() => screen.getByText('Older Lead'));
    expect(screen.getByLabelText('Unread')).toBeTruthy();
    await waitFor(() => expect(created).toHaveLength(1));

    await act(async () => { created[0].emit(channelFor('biz-aaaaaaaa', 'user-uid-0001'), { type: 'read', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c9' }); });
    await act(async () => { jest.advanceTimersByTime(400); });
    await waitFor(() => expect(screen.queryByLabelText('Unread')).toBeNull());
    expect(screen.getAllByText('Older Lead')).toHaveLength(1);
    expect(screen.getAllByText('Ann')).toHaveLength(1);
    jest.useRealTimers();
  });

  it('drops a loaded conversation the server says is no longer reachable', async () => {
    jest.useFakeTimers();
    const conversations = jest.fn(async (_s: any, q: any) => q.page === 2
      ? { data: [row('c9', 'Gone Lead', false, 'x')], meta: { page: 2, next_page: null } }
      : { data: [row('c1', 'Ann', false, 'top')], meta: { page: 1, next_page: 2 } });
    const conversation = jest.fn(async () => { throw new ApiError(404, 'not_found', 'Not found.'); });
    const { factory, created } = fakeTransports();
    await mount(fakeApi({ conversations, conversation }), factory, <ConversationsScreen {...({ navigation: { navigate: jest.fn(), setOptions: jest.fn() }, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann'));
    await fireEvent.press(screen.getByText('Load older leads'));
    await waitFor(() => screen.getByText('Gone Lead'));
    await waitFor(() => expect(created).toHaveLength(1));
    await act(async () => { created[0].emit(channelFor('biz-aaaaaaaa', 'user-uid-0001'), { type: 'status', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c9' }); });
    await act(async () => { jest.advanceTimersByTime(400); });
    await waitFor(() => expect(screen.queryByText('Gone Lead')).toBeNull());
    jest.useRealTimers();
  });

  it('reloads the list after a reconnect or app resume', async () => {
    jest.useFakeTimers();
    const conversations = jest.fn()
      .mockResolvedValueOnce({ data: [row('c1', 'Ann', false, 'before')], meta: { page: 1, next_page: null } })
      .mockResolvedValue({ data: [row('c2', 'New Lead', true, 'arrived while offline'), row('c1', 'Ann', false, 'before')], meta: { page: 1, next_page: null } });
    const { factory, created } = fakeTransports();
    await mount(fakeApi({ conversations }), factory, <ConversationsScreen {...({ navigation: { navigate: jest.fn(), setOptions: jest.fn() }, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann'));
    await waitFor(() => expect(created).toHaveLength(1));
    await act(async () => { created[0].connect(); });
    await act(async () => { jest.advanceTimersByTime(400); });
    await waitFor(() => screen.getByText('New Lead'));
    jest.useRealTimers();
  });

  it('an older, slower inbox refresh cannot overwrite a newer one', async () => {
    jest.useFakeTimers();
    const deferred: Array<(v: any) => void> = [];
    const conversations = jest.fn()
      .mockResolvedValueOnce({ data: [row('c1', 'Ann', false, 'initial')], meta: { page: 1, next_page: null } })
      .mockImplementation(() => new Promise((r) => { deferred.push(r); }));
    const { factory, created } = fakeTransports();
    await mount(fakeApi({ conversations }), factory, <ConversationsScreen {...({ navigation: { navigate: jest.fn(), setOptions: jest.fn() }, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann'));
    await waitFor(() => expect(created).toHaveLength(1));
    const ch = channelFor('biz-aaaaaaaa', 'user-uid-0001');
    const evt = { type: 'received', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' };

    await act(async () => { created[0].emit(ch, evt); });
    await act(async () => { jest.advanceTimersByTime(400); });      // refresh #1 starts (slow)
    await act(async () => { created[0].emit(ch, evt); });
    await act(async () => { jest.advanceTimersByTime(400); });      // refresh #2 starts
    await waitFor(() => expect(deferred).toHaveLength(2));

    await act(async () => { deferred[1]({ data: [row('c1', 'Ann', true, 'NEWER preview')], meta: { page: 1, next_page: null } }); });
    await waitFor(() => screen.getByText('NEWER preview'));
    await act(async () => { deferred[0]({ data: [row('c1', 'Ann', false, 'OLDER preview')], meta: { page: 1, next_page: null } }); });
    expect(screen.getByText('NEWER preview')).toBeTruthy();
    expect(screen.queryByText('OLDER preview')).toBeNull();
    jest.useRealTimers();
  });
  it('a conversation named by a superseded refresh is still reconciled by the newer one', async () => {
    jest.useFakeTimers();
    const deferred: Array<(v: any) => void> = [];
    const first = { data: [row('c1', 'Ann', false, 'top')], meta: { page: 1, next_page: 2 } };
    const conversations = jest.fn(async (_s: any, q: any) => {
      if (q.page === 2) return { data: [row('c9', 'Older Lead', true, 'unread preview')], meta: { page: 2, next_page: null } };
      if (conversations.mock.calls.filter((c: any[]) => c[1].page !== 2).length === 1) return first;
      return new Promise((r) => { deferred.push(r); });
    });
    const conversation = jest.fn(async () => ({ conversation: row('c9', 'Older Lead', false, 'unread preview'), contact_uid: null, has_more: false, messages: [] }));
    const { factory, created } = fakeTransports();
    await mount(fakeApi({ conversations, conversation }), factory, <ConversationsScreen {...({ navigation: { navigate: jest.fn(), setOptions: jest.fn() }, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann'));
    await fireEvent.press(screen.getByText('Load older leads'));
    await waitFor(() => screen.getByText('Older Lead'));
    await waitFor(() => expect(created).toHaveLength(1));
    const ch = channelFor('biz-aaaaaaaa', 'user-uid-0001');

    await act(async () => { created[0].emit(ch, { type: 'read', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c9' }); });
    await act(async () => { jest.advanceTimersByTime(400); });      // refresh #1 (names c9) starts, slow
    await act(async () => { created[0].emit(ch, { type: 'received', business_uid: 'biz-aaaaaaaa', conversation_uid: 'c1' }); });
    await act(async () => { jest.advanceTimersByTime(400); });      // refresh #2 (names only c1) supersedes it
    await waitFor(() => expect(deferred).toHaveLength(2));

    await act(async () => { deferred[1](first); });
    await waitFor(() => expect(screen.queryByLabelText('Unread')).toBeNull());
    await act(async () => { deferred[0](first); });
    expect(screen.queryByLabelText('Unread')).toBeNull();
    jest.useRealTimers();
  });
});
