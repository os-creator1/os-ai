import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { AuthProvider } from '../src/auth/AuthContext';
import { ApiError } from '../src/api/client';
import { ConversationDetailScreen } from '../src/screens/ConversationDetailScreen';
import { statusLabel } from '../src/conversations/status';

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

const business = { workspace_uid: 'ws-1', workspace_name: 'Alpha WS', business_uid: 'biz-1', name: 'Alpha Studio', features: { conversations: true, contacts: true } };
const me = { user: { uid: 'u1', name: 'Pat', email: 'pat@example.test' }, businesses: [business] };
const scope = { workspaceUid: 'ws-1', businessUid: 'biz-1' };

const inbound = { id: 1, direction: 'inbound', type: 'plain', body: 'Hello there', media_url: null, send_uid: null, status: null, failure_reason: null, status_message: null, retryable: false, created_at: '2026-01-01T10:00:00Z' };
const detail = (messages: any[], unread = false) => ({ conversation: { uid: 'c1', unread }, contact_uid: null, has_more: false, messages });

function fakeApi(over: Record<string, any> = {}): any {
  return {
    login: jest.fn(async () => ({ token: 't', token_type: 'Bearer', expires_at: new Date(Date.now() + 1e9).toISOString(), user: me.user })),
    logout: jest.fn(), me: jest.fn(async () => me), registerPush: jest.fn(),
    conversation: jest.fn(async () => detail([inbound])),
    markRead: jest.fn(async () => ({ uid: 'c1', unread: false })),
    reply: jest.fn(async (_s, _u, _b, key) => ({ message: { send_uid: key, status: 'sent' } })),
    retry: jest.fn(async (_s, _u, key) => ({ message: { send_uid: key, status: 'sent' } })),
    ...over,
  };
}
const nav: any = { navigate: jest.fn(), setOptions: jest.fn() };

async function open(api = fakeApi()) {
  const { Login } = require('./helpers');
  await render(
    <AuthProvider apiOverride={api}>
      <Login api={api}><ConversationDetailScreen {...({ navigation: nav, route: { params: { uid: 'c1', title: 'Ann' } } } as any)} /></Login>
    </AuthProvider>,
  );
  await waitFor(() => screen.getByText('Hello there'));
  return api;
}
async function type(text: string) { await fireEvent.changeText(screen.getByLabelText('Message'), text); }

describe('composer', () => {
  it('sends the trimmed text with a UUID idempotency key, clears the box and refreshes the thread', async () => {
    const api = fakeApi();
    await open(api);
    await type('  On my way!  ');
    api.conversation.mockImplementation(async () => detail([inbound, { ...inbound, id: 2, direction: 'outbound', body: 'On my way!', send_uid: api.reply.mock.calls[0][3], status: 'delivered' }]));
    await fireEvent.press(screen.getByLabelText('Send'));
    await waitFor(() => expect(api.reply).toHaveBeenCalledTimes(1));
    const [s, uid, body, key] = api.reply.mock.calls[0];
    expect(s).toEqual(scope); expect(uid).toBe('c1'); expect(body).toBe('On my way!');
    expect(key).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/);
    await waitFor(() => screen.getByText(/✓✓ Delivered/));
    expect(screen.getByLabelText('Message').props.value).toBe('');
    expect(screen.queryByText('Sending…')).toBeNull();
  });

  it('keeps a confirmed outgoing bubble visible if the post-send history refresh fails', async () => {
    const conversation = jest.fn()
      .mockResolvedValueOnce(detail([inbound]))
      .mockRejectedValueOnce(new ApiError(0, 'network', 'offline during refresh'));
    const api = fakeApi({ conversation });
    await open(api);
    await type('Confirmed request');
    await fireEvent.press(screen.getByLabelText('Send'));
    await waitFor(() => screen.getByText(/Message accepted, but history could not refresh/));
    expect(screen.getByText('Confirmed request')).toBeTruthy();
    // A confirmed operation must never get a second-send Retry just because
    // reading history is temporarily unavailable.
    expect(screen.queryByText('Retry')).toBeNull();
    expect(api.reply).toHaveBeenCalledTimes(1);
  });

  it('serializes older-page loads and never inserts duplicate history rows', async () => {
    let resolveOlder: (v: any) => void = () => {};
    // Startup can fetch the thread more than once during auth hydration;
    // defer only the cursor request, never a plain initial thread read.
    const conversation = jest.fn(async (_scope?: unknown, _uid?: unknown, before?: number) =>
      before === undefined
        ? { ...detail([inbound]), has_more: true }
        : new Promise((resolve) => { resolveOlder = resolve; }));
    const api = fakeApi({ conversation });
    await open(api);
    // The button's async onPress intentionally remains pending while the
    // mocked network request is unresolved; don't await that press here.
    fireEvent.press(screen.getByText('Load earlier messages'));
    // Wait for React Native to apply the header's in-flight state before
    // issuing a second tap; the cursor request itself remains unresolved.
    await waitFor(() => expect(conversation.mock.calls.filter((args: unknown[]) => args[2] === 1)).toHaveLength(1));
    await waitFor(() => screen.getByText('Loading earlier messages…'));
    fireEvent.press(screen.getByText('Loading earlier messages…'));
    expect(conversation.mock.calls.filter((args: unknown[]) => args[2] === 1)).toHaveLength(1);
    resolveOlder({ ...detail([{ ...inbound, id: 0, body: 'Oldest inquiry' }, inbound]), has_more: false });
    await waitFor(() => screen.getByText('Oldest inquiry'));
    expect(screen.getAllByText('Oldest inquiry')).toHaveLength(1);
    expect(screen.getAllByText('Hello there')).toHaveLength(1);
  });

  it('does not send an empty message', async () => {
    const api = await open();
    await type('   ');
    await fireEvent.press(screen.getByLabelText('Send'));
    expect(api.reply).not.toHaveBeenCalled();
  });

  it('cannot double-send: repeated Send taps while a request is in flight make one request', async () => {
    let release: (v: any) => void = () => {};
    const api = fakeApi({ reply: jest.fn(() => new Promise((r) => { release = r; })) });
    await open(api);
    await type('Only once');
    await fireEvent.press(screen.getByLabelText('Send'));
    await fireEvent.press(screen.getByLabelText('Send'));
    await fireEvent.press(screen.getByLabelText('Send'));
    expect(api.reply).toHaveBeenCalledTimes(1);
    expect(screen.getByText('Sending…')).toBeTruthy();
    release({ message: { send_uid: 'x', status: 'sent' } });
    await waitFor(() => expect(screen.queryByText('Sending…')).toBeNull());
  });

  it('shows a refusal once, without a Retry, when the server says it is not retryable (opted out)', async () => {
    const api = fakeApi({ reply: jest.fn(async () => { throw new ApiError(422, 'opted_out', 'This contact has opted out of text messages, so nothing was sent.'); }) });
    await open(api);
    await type('Hi');
    await fireEvent.press(screen.getByLabelText('Send'));
    await waitFor(() => screen.getByText(/opted out/));
    expect(screen.queryByText('Retry')).toBeNull();
    expect(api.reply).toHaveBeenCalledTimes(1);
  });

  it('after a network failure, Retry resends the SAME idempotency key (so it cannot become a second SMS)', async () => {
    const reply = jest.fn()
      .mockRejectedValueOnce(new ApiError(0, 'network', 'Could not reach the server.'))
      .mockImplementation(async (_s, _u, _b, key) => ({ message: { send_uid: key, status: 'sent' } }));
    const api = fakeApi({ reply });
    await open(api);
    await type('Are you free?');
    await fireEvent.press(screen.getByLabelText('Send'));
    await waitFor(() => screen.getByText(/No connection/));
    await fireEvent.press(screen.getByText('Retry'));
    await waitFor(() => expect(reply).toHaveBeenCalledTimes(2));
    expect(reply.mock.calls[1][3]).toBe(reply.mock.calls[0][3]);
    expect(api.retry).not.toHaveBeenCalled();
  });

  it('a server-recorded failure offers Retry through the retry endpoint with the same send_uid', async () => {
    const key = '11111111-1111-4111-8111-111111111111';
    const reply = jest.fn(async (_s?: unknown, _u?: unknown, _b?: unknown, _k?: unknown) => { throw new ApiError(422, 'insufficient_balance', 'Not enough messaging balance.', true, key); });
    const api = fakeApi({ reply });
    await open(api);
    await type('Quote attached');
    await fireEvent.press(screen.getByLabelText('Send'));
    await waitFor(() => screen.getByText('Not enough messaging balance.'));
    await fireEvent.press(screen.getByText('Retry'));
    await waitFor(() => expect(api.retry).toHaveBeenCalledTimes(1));
    expect(api.retry.mock.calls[0][2]).toBe(reply.mock.calls[0][3]);
    expect(api.reply).toHaveBeenCalledTimes(1);
  });

  it('shows a fresh retry refusal on an existing failed bubble instead of silently ignoring it', async () => {
    const key = '11111111-1111-4111-8111-111111111111';
    const failed = {
      ...inbound, id: 5, direction: 'outbound', body: 'Please confirm',
      send_uid: key, status: 'failed', status_message: 'Original attempt failed.', retryable: true,
    };
    const api = fakeApi({
      conversation: jest.fn(async () => detail([inbound, failed])),
      retry: jest.fn(async () => { throw new ApiError(422, 'insufficient_balance', 'Add funds to retry this SMS.', true, key); }),
    });
    await open(api);
    await fireEvent.press(screen.getByText('Retry'));
    await waitFor(() => screen.getByText('Add funds to retry this SMS.'));
    expect(screen.getByText('Original attempt failed.')).toBeTruthy();
    expect(api.retry).toHaveBeenCalledWith(scope, 'c1', key);
    expect(screen.getByText('Retry')).toBeTruthy();
  });

  it('clears an unconfirmed retry warning after refresh confirms the persisted outcome', async () => {
    const key = '33333333-3333-4333-8333-333333333333';
    const failed = {
      ...inbound, id: 5, direction: 'outbound', body: 'Still waiting',
      send_uid: key, status: 'failed', status_message: 'Previous send failed.', retryable: true,
    };
    const conversation = jest.fn()
      .mockResolvedValueOnce(detail([inbound, failed]))
      .mockRejectedValueOnce(new ApiError(0, 'network', 'History unavailable'))
      .mockResolvedValue(detail([inbound, {
        ...failed, status: 'delivery_failed',
        status_message: 'Carrier could not deliver.', retryable: true,
      }]));
    const api = fakeApi({ conversation });
    await open(api);

    fireEvent.press(screen.getByText('Retry'));
    await waitFor(() => screen.getByText(/Message accepted, but history could not refresh/));
    expect(api.retry).toHaveBeenCalledTimes(1);

    fireEvent(screen.getByTestId('message-refresh'), 'refresh');
    await waitFor(() => screen.getByText('Carrier could not deliver.'));
    await waitFor(() => expect(screen.queryByText(/Message accepted, but history could not refresh/)).toBeNull());
    expect(screen.getByText('Retry')).toBeTruthy();
  });

  it('renders a delivery-failed message with its reason and a Retry that calls retry()', async () => {
    const failed = { ...inbound, id: 5, direction: 'outbound', body: 'Missed it', send_uid: '22222222-2222-4222-8222-222222222222', status: 'delivery_failed', status_message: 'The carrier could not deliver this message.', retryable: true };
    const api = fakeApi({ conversation: jest.fn(async () => detail([inbound, failed])) });
    await open(api);
    expect(screen.getByText(/Delivery failed/)).toBeTruthy();
    expect(screen.getByText('The carrier could not deliver this message.')).toBeTruthy();
    await fireEvent.press(screen.getByText('Retry'));
    await waitFor(() => expect(api.retry).toHaveBeenCalledWith(scope, 'c1', '22222222-2222-4222-8222-222222222222'));
  });
});

describe('unread handling', () => {
  it('marks an unread conversation read once when it is opened', async () => {
    const api = fakeApi({ conversation: jest.fn(async () => detail([inbound], true)) });
    await open(api);
    await waitFor(() => expect(api.markRead).toHaveBeenCalledTimes(1));
    expect(api.markRead).toHaveBeenCalledWith(scope, 'c1');
  });

  it('does not call mark-read for a conversation that is already read', async () => {
    const api = await open();
    expect(api.markRead).not.toHaveBeenCalled();
  });
});

describe('statusLabel', () => {
  it('maps every delivery state to its indicator', () => {
    expect(statusLabel({ status: 'sending' })).toBe('Sending…');
    expect(statusLabel({ status: 'sent' })).toBe('✓ Sent');
    expect(statusLabel({ status: 'delivered' })).toBe('✓✓ Delivered');
    expect(statusLabel({ status: 'failed' })).toBe('Not sent');
    expect(statusLabel({ status: 'delivery_failed' })).toBe('Delivery failed');
    expect(statusLabel({ status: null })).toBeNull();
  });
});
