import React from 'react';
import { Text } from 'react-native';
import { act, render, screen, waitFor } from '@testing-library/react-native';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { NotificationRouter } from '../src/navigation/NotificationRouter';
import { isActiveConversation, parseNotificationTarget, setActiveConversation } from '../src/notifications/target';
import * as Notifications from 'expo-notifications';

jest.mock('expo-secure-store', () => {
  const m = new Map<string, string>();
  return {
    getItemAsync: async (k: string) => m.get(k) ?? null,
    setItemAsync: async (k: string, v: string) => { m.set(k, v); },
    deleteItemAsync: async (k: string) => { m.delete(k); },
  };
});
jest.mock('expo-notifications', () => ({
  addNotificationResponseReceivedListener: jest.fn(),
  getLastNotificationResponseAsync: jest.fn(async () => null),
}));
jest.mock('expo-device', () => ({ isDevice: false }));
jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { version: '0.1.0', extra: {} }, deviceName: 'Test phone' } }));

const bizA = { workspace_uid: 'ws-aaaaaaaa', workspace_name: 'A', business_uid: 'biz-aaaaaaaa', name: 'Alpha', features: { conversations: true, contacts: true } };
const bizB = { workspace_uid: 'ws-bbbbbbbb', workspace_name: 'B', business_uid: 'biz-bbbbbbbb', name: 'Bravo', features: { conversations: true, contacts: true } };
const user = { uid: 'user-uid-0001', name: 'Pat', email: 'pat@example.test' };

const payload = (b: string, c: string) => ({ v: 1, t: 'message', b, c });
const response = (id: string, data: unknown) => ({ notification: { request: { identifier: id, content: { data } } } }) as any;

function setup(businesses: any[], meImpl?: () => Promise<any>) {
  let tap: (r: any) => void = () => undefined;
  (Notifications.addNotificationResponseReceivedListener as jest.Mock).mockImplementation((cb) => { tap = cb; return { remove: jest.fn() }; });
  (Notifications.getLastNotificationResponseAsync as jest.Mock).mockResolvedValue(null);
  const me = jest.fn(meImpl ?? (async () => ({ user, businesses })));
  const api: any = { login: jest.fn(async () => ({ token: 't', token_type: 'Bearer', expires_at: new Date(Date.now() + 1e9).toISOString(), user })), logout: jest.fn(), me, registerPush: jest.fn() };
  const open = jest.fn();
  const rejected = jest.fn();
  function Boot() {
    const { signIn, signedIn, scope, signOut } = useAuth();
    const started = React.useRef(false);
    React.useEffect(() => { if (!started.current) { started.current = true; void signIn('pat@example.test', 'pw').catch(() => undefined); } }, [signIn]);
    return <><Text>{signedIn ? `in:${scope?.businessUid ?? 'none'}` : 'out'}</Text><Text onPress={() => void signOut()}>signout</Text></>;
  }
  return { api, me, open, rejected, tap: (r: any) => act(async () => { tap(r); }), ui: (
    <AuthProvider apiOverride={api}><Boot /><NotificationRouter openConversation={open} onRejected={rejected} /></AuthProvider>
  ) };
}

describe('parseNotificationTarget', () => {
  it('accepts only the exact opaque shape', () => {
    expect(parseNotificationTarget(payload('biz-aaaaaaaa', 'conv-uid-0001'))).toEqual({ businessUid: 'biz-aaaaaaaa', conversationUid: 'conv-uid-0001' });
    expect(parseNotificationTarget({ v: 2, t: 'message', b: 'biz-aaaaaaaa', c: 'conv-uid-0001' })).toBeNull();
    expect(parseNotificationTarget({ v: 1, t: 'call', b: 'biz-aaaaaaaa', c: 'conv-uid-0001' })).toBeNull();
    expect(parseNotificationTarget({ v: 1, t: 'message', b: '../../etc', c: 'conv-uid-0001' })).toBeNull();
    expect(parseNotificationTarget({ v: 1, t: 'message', b: 'biz-aaaaaaaa' })).toBeNull();
    expect(parseNotificationTarget(null)).toBeNull();
    expect(parseNotificationTarget('x')).toBeNull();
  });
  it('tracks the open conversation so a foreground push for it can be suppressed', () => {
    setActiveConversation('c1');
    expect(isActiveConversation('c1')).toBe(true);
    expect(isActiveConversation('c2')).toBe(false);
    setActiveConversation(null);
    expect(isActiveConversation('c1')).toBe(false);
  });
});

describe('NotificationRouter', () => {
  it('re-checks access with the server, selects the tapped business and opens the conversation', async () => {
    const t = setup([bizA, bizB]);
    await render(t.ui);
    await waitFor(() => screen.getByText(/^in:/));
    const callsBefore = t.me.mock.calls.length;
    await t.tap(response('n1', payload('biz-bbbbbbbb', 'conv-uid-0001')));
    await waitFor(() => expect(t.open).toHaveBeenCalledWith('conv-uid-0001'));
    expect(t.me.mock.calls.length).toBeGreaterThan(callsBefore); // access was re-asked, not assumed
    expect(screen.getByText('in:biz-bbbbbbbb')).toBeTruthy();
    expect(t.rejected).not.toHaveBeenCalled();
  });

  it('refuses a business the server no longer lists, opens nothing and does not switch business', async () => {
    const t = setup([bizA]);
    await render(t.ui);
    await waitFor(() => screen.getByText(/^in:/));
    await t.tap(response('n2', payload('biz-bbbbbbbb', 'conv-uid-0001')));
    await waitFor(() => expect(t.rejected).toHaveBeenCalled());
    expect(t.open).not.toHaveBeenCalled();
    expect(screen.queryByText('in:biz-bbbbbbbb')).toBeNull();
  });

  it('refuses a business whose conversations feature is not available', async () => {
    const t = setup([bizA, { ...bizB, features: { conversations: false, contacts: true } }]);
    await render(t.ui);
    await waitFor(() => screen.getByText(/^in:/));
    await t.tap(response('n3', payload('biz-bbbbbbbb', 'conv-uid-0001')));
    await waitFor(() => expect(t.rejected).toHaveBeenCalled());
    expect(t.open).not.toHaveBeenCalled();
  });

  it('ignores a malformed payload and handles the same notification only once', async () => {
    const t = setup([bizA, bizB]);
    await render(t.ui);
    await waitFor(() => screen.getByText(/^in:/));
    await t.tap(response('n4', { v: 1, t: 'message', b: 'x', c: 'y' }));
    await t.tap(response('n5', payload('biz-aaaaaaaa', 'conv-uid-0001')));
    await waitFor(() => expect(t.open).toHaveBeenCalledTimes(1));
    await t.tap(response('n5', payload('biz-aaaaaaaa', 'conv-uid-0001')));
    expect(t.open).toHaveBeenCalledTimes(1);
  });

  it('does not navigate if the server cannot be reached to confirm access', async () => {
    let offline = false;
    const t = setup([bizA, bizB], async () => { if (offline) throw new Error('offline'); return { user, businesses: [bizA, bizB] }; });
    await render(t.ui);
    await waitFor(() => screen.getByText(/^in:/));
    offline = true;
    await t.tap(response('n6', payload('biz-bbbbbbbb', 'conv-uid-0001')));
    await waitFor(() => expect(t.rejected).toHaveBeenCalled());
    expect(t.open).not.toHaveBeenCalled();
  });

  it('drops a pending tap on sign-out', async () => {
    const t = setup([bizA, bizB]);
    await render(t.ui);
    await waitFor(() => screen.getByText(/^in:/));
    await act(async () => { (screen.getByText('signout').props.onPress as () => void)(); });
    await waitFor(() => screen.getByText('out'));
    await t.tap(response('n7', payload('biz-aaaaaaaa', 'conv-uid-0001')));
    expect(t.open).not.toHaveBeenCalled();
  });
});
