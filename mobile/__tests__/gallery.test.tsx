import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react-native';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { DesignGalleryScreen, DesignPreviewScreen } from '../src/gallery/GalleryScreens';
import { GALLERY } from '../src/gallery/registry';
import { SettingsScreen } from '../src/screens/SettingsScreen';

jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { version: '0.1.0' } } }));
jest.mock('../src/notifications/permission', () => ({ getPushPermission: jest.fn(async () => 'granted') }));
const mockAuth = {
  me: { user: { uid: 'u1', name: 'Pat Doe', email: 'pat@example.test' }, businesses: [] },
  business: { name: 'Alpha Studio', workspace_name: 'Alpha WS' },
  clearBusiness: jest.fn(), signOut: jest.fn(async () => undefined),
};
jest.mock('../src/auth/AuthContext', () => ({ useAuth: () => mockAuth }));

const metrics = { frame: { x: 0, y: 0, width: 390, height: 844 }, insets: { top: 47, left: 0, right: 0, bottom: 34 } };
const withInsets = (ui: React.ReactElement) => <SafeAreaProvider initialMetrics={metrics}>{ui}</SafeAreaProvider>;

describe('Design gallery', () => {
  it('covers every planned screen from the design, each with its web feature and API needs', () => {
    expect(GALLERY.map((e) => e.title)).toEqual([
      'New conversation', 'Attachments + photo messages', 'Templates sheet', 'Thread actions', 'Internal notes in thread', 'Email message',
      'Inbox filters', 'Activity centre', 'Notification preferences', 'Notifications explainer', 'Settings — About', 'Calendar — agenda',
      'Calendar — day', 'Appointment detail', 'New appointment', 'Opportunities — pipeline', 'Opportunity detail', 'New opportunity',
      'Dialer', 'Call history', 'Incoming call', 'In call', 'Voicemail',
    ]);
    for (const e of GALLERY) {
      expect(e.mirrors.length).toBeGreaterThan(0);
      expect(e.api.length).toBeGreaterThan(0);
    }
    expect(new Set(GALLERY.map((e) => e.id)).size).toBe(GALLERY.length);
  });

  it.each(GALLERY.map((e) => [e.id]))('renders %s from fake data alone', async (id) => {
    // No AuthProvider / API client is mounted beyond a stub: a preview that tried to load data would fail here.
    const goBack = jest.fn();
    await render(withInsets(<DesignPreviewScreen {...({ navigation: { goBack }, route: { params: { id } } } as any)} />));
    expect(screen.getByTestId(`preview-${id}`)).toBeTruthy();
  });

  it('lists the entries by area and opens a preview', async () => {
    const navigate = jest.fn();
    await render(withInsets(<DesignGalleryScreen {...({ navigation: { navigate, goBack: jest.fn() }, route: { params: undefined } } as any)} />));
    expect(screen.getByText('CALENDAR')).toBeTruthy();
    expect(screen.getByText('OPPORTUNITIES')).toBeTruthy();
    await fireEvent.press(screen.getByText('Dialer'));
    expect(navigate).toHaveBeenCalledWith('DesignPreview', { id: 'dialer' });
  });

  it('a preview goes back to the gallery', async () => {
    const goBack = jest.fn();
    await render(withInsets(<DesignPreviewScreen {...({ navigation: { goBack }, route: { params: { id: 'about' } } } as any)} />));
    await fireEvent.press(screen.getByLabelText('Back'));
    expect(goBack).toHaveBeenCalled();
  });

  it('keeps local interactions working (calendar Agenda ⇄ Day, dialer digits)', async () => {
    await render(withInsets(<DesignPreviewScreen {...({ navigation: { goBack: jest.fn() }, route: { params: { id: 'calendar-agenda' } } } as any)} />));
    expect(screen.getByText('Bell birthday · 360 booth')).toBeTruthy();
    await fireEvent.press(screen.getByText('Day'));
    expect(screen.getByText('10:30 Discovery call · Jordan Lee')).toBeTruthy();

    await render(withInsets(<DesignPreviewScreen {...({ navigation: { goBack: jest.fn() }, route: { params: { id: 'dialer' } } } as any)} />));
    await fireEvent.press(screen.getByLabelText('7'));
    expect(screen.getByLabelText('Number (737) 555-017')).toBeTruthy();
  });
});

describe('Settings entry', () => {
  const g = globalThis as unknown as { __DEV__: boolean };
  const dev = g.__DEV__;
  afterEach(() => { g.__DEV__ = dev; });

  it('shows "Design gallery" in development builds and opens it', async () => {
    g.__DEV__ = true;
    const navigate = jest.fn();
    await render(<SettingsScreen {...({ navigation: { navigate } } as any)} />);
    await fireEvent.press(screen.getByText('Design gallery'));
    expect(navigate).toHaveBeenCalledWith('DesignGallery');
  });

  it('hides it in release builds', async () => {
    g.__DEV__ = false;
    await render(<SettingsScreen {...({ navigation: { navigate: jest.fn() } } as any)} />);
    expect(screen.queryByText('Design gallery')).toBeNull();
  });
});
