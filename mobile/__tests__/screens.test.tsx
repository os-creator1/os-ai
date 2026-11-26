import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import * as SecureStore from 'expo-secure-store';
import { ApiError } from '../src/api/client';
import { LoginScreen } from '../src/screens/LoginScreen';
import { BusinessSelectorScreen } from '../src/screens/BusinessSelectorScreen';
import { CallsScreen } from '../src/screens/CallsScreen';
import { ContactProfileScreen } from '../src/screens/ContactProfileScreen';
import { ConversationDetailScreen } from '../src/screens/ConversationDetailScreen';
import { ConversationsScreen } from '../src/screens/ConversationsScreen';

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

function fakeApi(over: Record<string, any> = {}): any {
  return {
    login: jest.fn(async () => ({ token: 't', token_type: 'Bearer', expires_at: new Date(Date.now() + 1e9).toISOString(), user: me.user })),
    logout: jest.fn(async () => ({ signed_out: true })),
    me: jest.fn(async () => me),
    registerPush: jest.fn(),
    conversations: jest.fn(async () => ({ data: [
      { uid: 'c1', display_name: 'Ann Lee', phone: '+15551110001', unread: true, pinned: false, last_message: { direction: 'inbound', preview: 'Need a quote', at: null }, updated_at: null },
    ], meta: { page: 1, next_page: null } })),
    conversation: jest.fn(async () => ({ conversation: {}, contact_uid: 'k1', has_more: false, messages: [
      { id: 1, direction: 'inbound', type: 'plain', body: 'Hello there', media_url: null, created_at: '2026-01-01T10:00:00Z' },
      { id: 2, direction: 'outbound', type: 'plain', body: 'Hi, how can I help?', media_url: null, created_at: '2026-01-01T10:01:00Z' },
    ] })),
    contact: jest.fn(async () => ({ uid: 'k1', display_name: 'Ann Lee', phone: '+15551110001', email: 'ann@example.test', subscribed: false, fields: [{ tag: 'CITY', label: 'City', value: 'Austin' }], created_at: null })),
    callingCapability: jest.fn(async () => ({ available: false, reason: 'Telnyx is not in live mode on this platform.', phase: 'capability-only' })),
    ...over,
  };
}

const nav: any = { navigate: jest.fn(), setOptions: jest.fn() };

/** Renders a screen already signed in to the business, through the real AuthProvider. */
async function signedIn(ui: (api: any) => React.ReactElement, api = fakeApi()) {
  const { Login } = require('./helpers');
  const view = await render(<AuthProvider apiOverride={api}><Login api={api}>{ui(api)}</Login></AuthProvider>);
  await waitFor(() => expect(screen.queryByText('ready')).toBeTruthy());
  return { api, view };
}

describe('LoginScreen', () => {
  it('signs in and shows server refusals without exposing detail', async () => {
    const api = fakeApi({ login: jest.fn(async () => { throw new ApiError(401, 'invalid_credentials', 'These credentials do not match.'); }) });
    await render(<AuthProvider apiOverride={api}><LoginScreen /></AuthProvider>);
    await waitFor(() => screen.getByLabelText('Email'));
    await fireEvent.changeText(screen.getByLabelText('Email'), 'pat@example.test');
    await fireEvent.changeText(screen.getByLabelText('Password'), 'wrong-pass');
    await fireEvent.press(screen.getByText('Sign in'));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('These credentials do not match.'));
    expect(api.me).not.toHaveBeenCalled();
  });

  it('keeps Sign in disabled until both fields are filled', async () => {
    await render(<AuthProvider apiOverride={fakeApi()}><LoginScreen /></AuthProvider>);
    await waitFor(() => screen.getByLabelText('Email'));
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeDisabled();
  });
});

describe('signed-in screens', () => {
  it('lists the business selector from the server list only', async () => {
    await signedIn(() => <BusinessSelectorScreen />);
    expect(screen.getByText('Alpha Studio')).toBeTruthy();
  });

  it('loads conversations for the selected business scope and shows unread state', async () => {
    const { api } = await signedIn(() => <ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann Lee'));
    expect(api.conversations).toHaveBeenCalledWith({ workspaceUid: 'ws-1', businessUid: 'biz-1' }, expect.objectContaining({ filter: 'all' }));
    expect(screen.getByLabelText('Unread')).toBeTruthy();
    await fireEvent.press(screen.getByText('Ann Lee'));
    expect(nav.navigate).toHaveBeenCalledWith('ConversationDetail', { uid: 'c1', title: 'Ann Lee' });
  });

  it('loads older conversations when the server supplies a next page', async () => {
    const conversations = jest.fn(async (_scope, query) => query.page === 2
      ? { data: [
          { uid: 'c2', display_name: 'Older Lead', phone: '+15552220002', unread: false, pinned: false, last_message: null, updated_at: null },
        ], meta: { page: 2, next_page: null } }
      : { data: [
          { uid: 'c1', display_name: 'Ann Lee', phone: '+15551110001', unread: true, pinned: false, last_message: null, updated_at: null },
        ], meta: { page: 1, next_page: 2 } });
    await signedIn(() => <ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />, fakeApi({ conversations }));
    await waitFor(() => screen.getByText('Ann Lee'));
    await fireEvent.press(screen.getByText('Load older leads'));
    await waitFor(() => screen.getByText('Older Lead'));
    expect(conversations).toHaveBeenCalledWith(
      { workspaceUid: 'ws-1', businessUid: 'biz-1' },
      expect.objectContaining({ page: 2, filter: 'all' }),
    );
    expect(screen.getByText('Ann Lee')).toBeTruthy();
  });

  it('keeps the search field mounted and focused while changing a query', async () => {
    await signedIn(() => <ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann Lee'));
    await fireEvent.changeText(screen.getByLabelText('Search'), 'ann');
    // The input must remain in the tree even during the new request's loading state.
    await waitFor(() => expect(screen.getByLabelText('Search').props.value).toBe('ann'));
  });

  it('shows an error state with retry when the API refuses (e.g. foreign business)', async () => {
    const api = fakeApi({ conversations: jest.fn(async () => { throw new ApiError(404, 'not_found', 'Not found.'); }) });
    await signedIn(() => <ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />, api);
    await waitFor(() => screen.getByText('Not found.'));
    expect(screen.getByText('Try again')).toBeTruthy();
  });

  it('renders the thread with direction and links to the contact profile', async () => {
    await signedIn(() => <ConversationDetailScreen {...({ navigation: nav, route: { params: { uid: 'c1', title: 'Ann' } } } as any)} />);
    await waitFor(() => screen.getByText('Hello there'));
    expect(screen.getByText('Hi, how can I help?')).toBeTruthy();
    await fireEvent.press(screen.getByText('Contact profile'));
    expect(nav.navigate).toHaveBeenCalledWith('ContactProfile', { uid: 'k1' });
  });

  it('loads earlier messages using the oldest message id and keeps newer ones', async () => {
    const conversation = jest.fn(async (_scope, _uid, beforeId?: number) => beforeId === 2
      ? { conversation: {}, contact_uid: 'k1', messages: [
          { id: 1, direction: 'inbound', type: 'plain', body: 'Original inquiry', media_url: null, created_at: '2026-01-01T09:00:00Z' },
        ], has_more: false }
      : { conversation: {}, contact_uid: 'k1', messages: [
          { id: 2, direction: 'outbound', type: 'plain', body: 'Newest reply', media_url: null, created_at: '2026-01-01T10:00:00Z' },
        ], has_more: true });
    await signedIn(() => <ConversationDetailScreen {...({ navigation: nav, route: { params: { uid: 'c1', title: 'Ann' } } } as any)} />, fakeApi({ conversation }));
    await waitFor(() => screen.getByText('Newest reply'));
    fireEvent.press(screen.getByText('Load earlier messages'));
    await waitFor(() => screen.getByText('Original inquiry'));
    expect(screen.getByText('Newest reply')).toBeTruthy();
    expect(conversation).toHaveBeenCalledWith({ workspaceUid: 'ws-1', businessUid: 'biz-1' }, 'c1', 2);
  });

  it('renders the contact profile fields and opt-out state', async () => {
    await signedIn(() => <ContactProfileScreen {...({ navigation: nav, route: { params: { uid: 'k1' } } } as any)} />);
    await waitFor(() => screen.getByText('ann@example.test'));
    expect(screen.getByText('Austin')).toBeTruthy();
    expect(screen.getByText('Opted out of messages')).toBeTruthy();
  });

  it('shows calls as a placeholder and the server-reported reason', async () => {
    await signedIn(() => <CallsScreen />);
    await waitFor(() => screen.getByText(/Not available yet/));
    expect(screen.getByText('Voice calling is coming in the next milestone.')).toBeTruthy();
  });
});

describe('bottom bars clear the system navigation bar (edge-to-edge)', () => {
  // A phone with a 48dp Android navigation bar at the bottom.
  const metrics = { frame: { x: 0, y: 0, width: 400, height: 800 }, insets: { top: 24, left: 0, right: 0, bottom: 48 } };
  const withInsets = (ui: React.ReactElement) => <SafeAreaProvider initialMetrics={metrics}>{ui}</SafeAreaProvider>;
  const paddingBottom = (testID: string) => StyleSheet.flatten(screen.getByTestId(testID).props.style).paddingBottom;

  it('lifts the Switch business / Sign out bar above the navigation bar', async () => {
    await signedIn(() => withInsets(<ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />));
    await waitFor(() => screen.getByText('Ann Lee'));
    expect(paddingBottom('conversations-bottom-bar')).toBe(8 + 48);
    expect(screen.getByText('Sign out')).toBeTruthy();
  });

  it('lifts the business selector Sign out button above the navigation bar', async () => {
    await signedIn(() => withInsets(<BusinessSelectorScreen />));
    expect(paddingBottom('business-selector')).toBe(16 + 48);
  });

  it('keeps the normal spacing when there is no bottom inset', async () => {
    await signedIn(() => <ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByText('Ann Lee'));
    expect(paddingBottom('conversations-bottom-bar')).toBe(8);
  });
});

describe('restoring a saved mobile session', () => {
  function SessionProbe() {
    const { ready, signedIn, restoreError, retryRestore } = useAuth();
    return (
      <View>
        <Text>{ready ? 'hydration complete' : 'hydrating'}</Text>
        <Text>{signedIn ? 'session restored' : 'session not restored'}</Text>
        {restoreError ? <Text>{restoreError}</Text> : null}
        <Pressable onPress={() => { void retryRestore(); }}><Text>Retry startup</Text></Pressable>
      </View>
    );
  }

  async function seedSavedToken() {
    await SecureStore.setItemAsync('mg.token', 'previously-valid-token');
    await SecureStore.setItemAsync('mg.expires', new Date(Date.now() + 3600000).toISOString());
    await SecureStore.deleteItemAsync('mg.scope');
  }

  it('preserves a valid stored token when the network is down and restores it on retry', async () => {
    await seedSavedToken();
    const meFn = jest.fn()
      .mockRejectedValueOnce(new ApiError(0, 'network', 'offline'))
      .mockResolvedValue(me);
    render(<AuthProvider apiOverride={fakeApi({ me: meFn })}><SessionProbe /></AuthProvider>);

    await waitFor(() => screen.getByText('Could not connect to MotionGrove. Check your connection and try again.'));
    expect(await SecureStore.getItemAsync('mg.token')).toBe('previously-valid-token');
    fireEvent.press(screen.getByText('Retry startup'));
    await waitFor(() => screen.getByText('session restored'));
    expect(meFn).toHaveBeenCalledTimes(2);
    expect(await SecureStore.getItemAsync('mg.token')).toBe('previously-valid-token');
    await SecureStore.deleteItemAsync('mg.token');
    await SecureStore.deleteItemAsync('mg.expires');
  });

  it('clears the saved token when the server truly rejects authentication', async () => {
    await seedSavedToken();
    const api = fakeApi({ me: jest.fn().mockRejectedValue(new ApiError(401, 'unauthenticated', 'Sign in again.')) });
    render(<AuthProvider apiOverride={api}><SessionProbe /></AuthProvider>);
    await waitFor(() => screen.getByText('hydration complete'));
    expect(await SecureStore.getItemAsync('mg.token')).toBeNull();
    expect(screen.getByText('session not restored')).toBeTruthy();
    expect(screen.queryByText('Could not connect to MotionGrove. Check your connection and try again.')).toBeNull();
  });
});
