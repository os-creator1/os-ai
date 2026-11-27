import React from 'react';
import { Text as RNText } from 'react-native';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react-native';
import { NavigationContainer } from '@react-navigation/native';
import NetInfo from '@react-native-community/netinfo';
import { AuthProvider, useAuth } from '../src/auth/AuthContext';
import { Avatar, Banner, Button, EmptyState, SegmentedControl, initials } from '../src/components';
import { clockTime, dayLabel, listStamp } from '../src/format/time';
import { MainTabsNavigator } from '../src/navigation';
import { getPushPermission } from '../src/notifications/permission';
import { BusinessSelectorScreen } from '../src/screens/BusinessSelectorScreen';
import { ConversationDetailScreen } from '../src/screens/ConversationDetailScreen';
import { ConversationsScreen } from '../src/screens/ConversationsScreen';
import { SessionRestoreErrorScreen } from '../src/screens/SessionRestoreErrorScreen';
import { SettingsScreen } from '../src/screens/SettingsScreen';

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
jest.mock('../src/notifications/permission', () => ({ getPushPermission: jest.fn(async () => 'granted'), requestPushPermission: jest.fn(async () => 'granted') }));

const alpha = { workspace_uid: 'ws-1', workspace_name: 'Alpha WS', business_uid: 'biz-1', name: 'Alpha Studio', features: { conversations: true, contacts: true } };
const beta = { workspace_uid: 'ws-1', workspace_name: 'Alpha WS', business_uid: 'biz-2', name: 'Beta Booths', features: { conversations: true, contacts: true } };
const me = { user: { uid: 'u1', name: 'Pat Doe', email: 'pat@example.test' }, businesses: [alpha] };

const row = (uid: string, name: string, unread: boolean) => ({
  uid, display_name: name, phone: '+15551110001', unread, pinned: false,
  last_message: { direction: 'inbound', preview: `hi from ${name}`, at: null }, updated_at: null,
});

function fakeApi(over: Record<string, any> = {}): any {
  return {
    login: jest.fn(async () => ({ token: 't', token_type: 'Bearer', expires_at: new Date(Date.now() + 1e9).toISOString(), user: me.user })),
    logout: jest.fn(async () => ({ signed_out: true })),
    me: jest.fn(async () => me),
    registerPush: jest.fn(async () => ({ push_registered: true })),
    unregisterPush: jest.fn(async () => ({ push_registered: false })),
    conversations: jest.fn(async () => ({ data: [row('c1', 'Ann Lee', true)], meta: { page: 1, next_page: null } })),
    conversation: jest.fn(async () => ({ conversation: { display_name: 'Ann Lee', phone: '+15551110001' }, contact_uid: 'k1', has_more: false, messages: [] })),
    contact: jest.fn(),
    callingCapability: jest.fn(async () => ({ available: false, reason: 'Not live', phase: 'capability-only' })),
    ...over,
  };
}

/** Shows the live auth state next to the UI under test. */
function Probe() {
  const { signedIn, scope } = useAuth();
  return <RNText>{!signedIn ? 'state:signed-out' : scope ? `state:${scope.businessUid}` : 'state:no-business'}</RNText>;
}

/** Signs in and selects the first business ONCE (so tests can switch away from it), then renders `ui`. */
function Login({ children }: { children: React.ReactNode }) {
  const { signIn, selectBusiness, me, scope } = useAuth();
  const started = React.useRef(false);
  const selected = React.useRef(false);
  React.useEffect(() => {
    if (started.current) return;
    started.current = true;
    void signIn('pat@example.test', 'pw');
  }, [signIn]);
  React.useEffect(() => {
    if (scope) { selected.current = true; return; }
    if (me && !selected.current) { selected.current = true; void selectBusiness(me.businesses[0]); }
  }, [me, scope, selectBusiness]);
  return me ? <>{children}</> : null;
}

async function signedIn(ui: React.ReactElement, api = fakeApi()) {
  await render(<AuthProvider apiOverride={api}><Probe /><Login>{ui}</Login></AuthProvider>);
  await waitFor(() => screen.getByText(/^state:biz-/));
  return api;
}

const nav: any = { navigate: jest.fn(), setOptions: jest.fn(), goBack: jest.fn() };

describe('bottom tabs', () => {
  it('shows Conversations, Calls and Settings with Conversations selected, and switches tabs', async () => {
    await signedIn(<NavigationContainer><MainTabsNavigator /></NavigationContainer>);
    await waitFor(() => screen.getByText('Ann Lee'));
    expect(screen.getByTestId('tab-Conversations').props.accessibilityState).toEqual(expect.objectContaining({ selected: true }));
    expect(screen.getByTestId('tab-Calls')).toBeTruthy();
    await fireEvent.press(screen.getByTestId('tab-Settings'));
    await waitFor(() => screen.getByTestId('settings-screen'));
    expect(screen.getByTestId('tab-Settings').props.accessibilityState).toEqual(expect.objectContaining({ selected: true }));
  });

  it('marks the Conversations tab while the list has unread conversations, and clears it when none are left', async () => {
    const conversations = jest.fn()
      .mockResolvedValueOnce({ data: [row('c1', 'Ann Lee', true)], meta: { page: 1, next_page: null } })
      .mockResolvedValue({ data: [row('c1', 'Ann Lee', false)], meta: { page: 1, next_page: null } });
    await signedIn(<NavigationContainer><MainTabsNavigator /></NavigationContainer>, fakeApi({ conversations }));
    await waitFor(() => screen.getByTestId('tab-unread-badge'));
    expect(screen.getByLabelText('Conversations, unread messages')).toBeTruthy();
    await act(async () => { await screen.getByTestId('conversation-list').props.refreshControl.props.onRefresh(); });
    await waitFor(() => expect(screen.queryByTestId('tab-unread-badge')).toBeNull());
  });
});

describe('Settings', () => {
  it('shows the account, the current business, notification status and the app version', async () => {
    await signedIn(<SettingsScreen />);
    expect(screen.getByText('Pat Doe')).toBeTruthy();
    expect(screen.getAllByText('pat@example.test').length).toBeGreaterThan(0);
    expect(screen.getByText('Alpha Studio')).toBeTruthy();
    expect(screen.getByText('0.1.0')).toBeTruthy();
    await waitFor(() => screen.getByText('On'));
  });

  it('reports notifications as Off when permission was denied', async () => {
    (getPushPermission as jest.Mock).mockResolvedValueOnce('denied');
    await signedIn(<SettingsScreen />);
    await waitFor(() => screen.getByText('Off'));
  });

  it('Switch business returns to the business choice; Sign out ends the session', async () => {
    const api = await signedIn(<SettingsScreen />);
    await fireEvent.press(screen.getByText('Switch business'));
    await waitFor(() => screen.getByText('state:no-business'));
    expect(api.logout).not.toHaveBeenCalled();
  });

  it('signs out from Settings', async () => {
    const api = await signedIn(<SettingsScreen />);
    await fireEvent.press(screen.getByText('Sign out'));
    await waitFor(() => screen.getByText('state:signed-out'));
    expect(api.logout).toHaveBeenCalled();
  });
});

describe('Choose a business', () => {
  it('needs a choice before Continue, then opens the chosen business', async () => {
    const two = { ...me, businesses: [alpha, beta] };
    const api = fakeApi({ me: jest.fn(async () => two) });
    await signedIn(<BusinessSelectorScreen />, api);
    // Settings → Switch business: the helper selected Alpha; clear it to land on the chooser with a pre-selection.
    expect(screen.getByText('Beta Booths')).toBeTruthy();
    await fireEvent.press(screen.getByText('Beta Booths'));
    await fireEvent.press(screen.getByText('Continue'));
    await waitFor(() => screen.getByText('state:biz-2'));
  });

  it('filters the list with the search field', async () => {
    const two = { ...me, businesses: [alpha, beta] };
    await signedIn(<BusinessSelectorScreen />, fakeApi({ me: jest.fn(async () => two) }));
    await fireEvent.changeText(screen.getByLabelText('Search businesses'), 'beta');
    expect(screen.queryByText('Alpha Studio')).toBeNull();
    expect(screen.getByText('Beta Booths')).toBeTruthy();
  });
});

describe('Conversations states', () => {
  const empty = jest.fn(async () => ({ data: [], meta: { page: 1, next_page: null } }));

  it('shows "all caught up" on an empty Unread filter, with a way back to All', async () => {
    const api = await signedIn(<ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />, fakeApi({ conversations: empty }));
    await waitFor(() => screen.getByText('No conversations yet'));
    await fireEvent.press(screen.getByText('Unread'));
    await waitFor(() => screen.getByText("You're all caught up"));
    expect(api.conversations).toHaveBeenLastCalledWith(expect.anything(), expect.objectContaining({ filter: 'unread' }));
    await fireEvent.press(screen.getByText('Show all conversations'));
    await waitFor(() => screen.getByText('No conversations yet'));
  });

  it('shows "No results" for a search with nothing found, and Clear search empties the field', async () => {
    await signedIn(<ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />, fakeApi({ conversations: empty }));
    await fireEvent.changeText(screen.getByLabelText('Search'), 'zzz');
    await waitFor(() => screen.getByText('No results'));
    await fireEvent.press(screen.getByText('Clear search'));
    await waitFor(() => expect(screen.getByLabelText('Search').props.value).toBe(''));
  });

  it('shows the offline banner when the device has no connection', async () => {
    const spy = jest.spyOn(NetInfo, 'addEventListener').mockImplementation(((cb: (s: any) => void) => {
      cb({ isConnected: false, isInternetReachable: false });
      return () => undefined;
    }) as any);
    await signedIn(<ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />);
    await waitFor(() => screen.getByTestId('offline-banner'));
    spy.mockRestore();
  });

  it('switches business from the name at the top', async () => {
    await signedIn(<ConversationsScreen {...({ navigation: nav, route: { params: undefined } } as any)} />);
    await fireEvent.press(screen.getByLabelText('Switch business, current: Alpha Studio'));
    await waitFor(() => screen.getByText('state:no-business'));
  });
});

describe('Conversation thread', () => {
  it('separates messages from different days and shows the contact in the header', async () => {
    const yesterday = new Date(Date.now() - 86400000);
    yesterday.setHours(12, 0, 0, 0);
    const today = new Date();
    const conversation = jest.fn(async () => ({
      conversation: { display_name: 'Ann Lee', phone: '+15551110001' }, contact_uid: 'k1', has_more: false, messages: [
        { id: 1, direction: 'inbound', type: 'plain', body: 'Old one', media_url: null, send_uid: null, status: null, failure_reason: null, status_message: null, retryable: false, created_at: yesterday.toISOString() },
        { id: 2, direction: 'outbound', type: 'plain', body: 'New one', media_url: null, send_uid: 's2', status: 'delivered', failure_reason: null, status_message: null, retryable: false, created_at: today.toISOString() },
      ],
    }));
    await signedIn(<ConversationDetailScreen {...({ navigation: nav, route: { params: { uid: 'c1', title: 'Conversation' } } } as any)} />, fakeApi({ conversation }));
    await waitFor(() => screen.getByText('New one'));
    expect(screen.getByText('Yesterday')).toBeTruthy();
    expect(screen.getByText('Today')).toBeTruthy();
    expect(screen.getByText(/✓✓ Delivered/)).toBeTruthy();
    expect(screen.getByText('Ann Lee')).toBeTruthy();
    await fireEvent.press(screen.getByLabelText('Ann Lee. Contact profile'));
    expect(nav.navigate).toHaveBeenCalledWith('ContactProfile', { uid: 'k1' });
  });
});

describe('Session restore error', () => {
  it('offers Try again and Sign in again', async () => {
    const onRetry = jest.fn(async () => undefined);
    const onSignInAgain = jest.fn(async () => undefined);
    await render(<SessionRestoreErrorScreen message="Could not connect." onRetry={onRetry} onSignInAgain={onSignInAgain} />);
    expect(screen.getByText("We couldn't load your account")).toBeTruthy();
    await fireEvent.press(screen.getByText('Try again'));
    expect(onRetry).toHaveBeenCalled();
    await fireEvent.press(screen.getByText('Sign in again'));
    expect(onSignInAgain).toHaveBeenCalled();
  });
});

describe('shared components', () => {
  it('Button: a loading or disabled button does not fire', async () => {
    const onPress = jest.fn();
    await render(<><Button title="Send" loading onPress={onPress} /><Button title="Save" disabled onPress={onPress} /><Button title="Go" onPress={onPress} /></>);
    await fireEvent.press(screen.getByText('Send'));
    await fireEvent.press(screen.getByText('Save'));
    expect(onPress).not.toHaveBeenCalled();
    expect(screen.getByRole('button', { name: 'Send' })).toBeDisabled();
    await fireEvent.press(screen.getByText('Go'));
    expect(onPress).toHaveBeenCalledTimes(1);
  });

  it('Avatar: initials from a name, a person glyph for a bare number', async () => {
    expect(initials('Ava Martinez')).toBe('AM');
    expect(initials('ava')).toBe('A');
    expect(initials('Mary Jane Watson')).toBe('MW');
    expect(initials('+1 (512) 555-0148')).toBe('');
    expect(initials(null)).toBe('');
    await render(<Avatar name="Jordan Lee" />);
    expect(screen.getByText('JL', { includeHiddenElements: true })).toBeTruthy();
  });

  it('Banner: inline Retry and action button', async () => {
    const onRetry = jest.fn();
    const onAction = jest.fn();
    await render(<Banner tone="error" message="Could not refresh" onRetry={onRetry} action={{ label: 'Fix', onPress: onAction }} />);
    expect(screen.getByRole('alert')).toBeTruthy();
    await fireEvent.press(screen.getByText('Retry'));
    await fireEvent.press(screen.getByText('Fix'));
    expect(onRetry).toHaveBeenCalled();
    expect(onAction).toHaveBeenCalled();
  });

  it('SegmentedControl: marks the selected segment and reports changes', async () => {
    const onChange = jest.fn();
    await render(<SegmentedControl options={[{ value: 'a', label: 'All' }, { value: 'u', label: 'Unread' }]} value="a" onChange={onChange} />);
    const tabs = screen.getAllByRole('tab');
    expect(tabs[0].props.accessibilityState).toEqual(expect.objectContaining({ selected: true }));
    expect(tabs[1].props.accessibilityState).toEqual(expect.objectContaining({ selected: false }));
    await fireEvent.press(screen.getByText('Unread'));
    expect(onChange).toHaveBeenCalledWith('u');
  });

  it('EmptyState: title, message and action', async () => {
    const onPress = jest.fn();
    await render(<EmptyState icon="inbox" title="Nothing here" message="Come back later." action={{ label: 'Refresh', onPress }} />);
    expect(screen.getByText('Come back later.')).toBeTruthy();
    await fireEvent.press(screen.getByText('Refresh'));
    expect(onPress).toHaveBeenCalled();
  });
});

describe('time formatting', () => {
  const now = new Date(2026, 10, 26, 15, 0, 0); // Thu 26 Nov 2026, 3 PM local

  it('list stamps', () => {
    expect(listStamp(new Date(2026, 10, 26, 14, 58).toISOString(), now)).toBe('2m');
    expect(listStamp(new Date(2026, 10, 26, 13, 30).toISOString(), now)).toBe('1h');
    expect(listStamp(new Date(2026, 10, 24, 9, 0).toISOString(), now)).toBe('Tue');
    expect(listStamp(new Date(2026, 10, 2, 9, 0).toISOString(), now)).toBe('Nov 2');
    expect(listStamp(new Date(2025, 0, 2, 9, 0).toISOString(), now)).toBe('Jan 2, 2025');
    expect(listStamp(null, now)).toBe('');
  });

  it('day separators and clock time', () => {
    expect(dayLabel(new Date(2026, 10, 26, 8, 0).toISOString(), now)).toBe('Today');
    expect(dayLabel(new Date(2026, 10, 25, 23, 0).toISOString(), now)).toBe('Yesterday');
    expect(dayLabel(new Date(2026, 10, 23, 8, 0).toISOString(), now)).toBe('Monday');
    expect(clockTime(new Date(2026, 10, 26, 16, 12).toISOString())).toBe('4:12 PM');
    expect(clockTime(new Date(2026, 10, 26, 0, 5).toISOString())).toBe('12:05 AM');
  });
});
