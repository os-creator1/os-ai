import React from 'react';
import { AppState } from 'react-native';
import { act, render, screen, waitFor } from '@testing-library/react-native';
import { NotificationBanner } from '../src/notifications/NotificationBanner';
import { getPushPermission } from '../src/notifications/permission';
import { registerForPush } from '../src/notifications/registerDevice';

jest.mock('expo-constants', () => ({ __esModule: true, default: { expoConfig: { version: '0.1.0' } } }));
const mockAuth = { api: { registerPush: jest.fn() } };   // stable across renders, like the real provider's api
jest.mock('../src/auth/AuthContext', () => ({ useAuth: () => mockAuth }));
jest.mock('../src/notifications/permission', () => ({ getPushPermission: jest.fn(), requestPushPermission: jest.fn() }));
jest.mock('../src/notifications/registerDevice', () => ({ registerForPush: jest.fn(async () => 'token') }));

describe('NotificationBanner', () => {
  it('registers the device when permission is granted in system Settings and the app returns to the foreground', async () => {
    let appHandler: (s: string) => void = () => undefined;
    jest.spyOn(AppState, 'addEventListener').mockImplementation(((_: string, h: (s: any) => void) => { appHandler = h; return { remove: jest.fn() }; }) as any);
    (getPushPermission as jest.Mock).mockResolvedValueOnce('denied').mockResolvedValue('granted');
    render(<NotificationBanner />);
    await waitFor(() => screen.getByText(/Notifications are turned off/));
    expect(registerForPush).not.toHaveBeenCalled();

    await act(async () => { appHandler('active'); });
    await waitFor(() => expect(registerForPush).toHaveBeenCalledTimes(1));
    expect(screen.queryByText(/Notifications are turned off/)).toBeNull();

    // Further foreground events do not register again.
    await act(async () => { appHandler('active'); });
    expect(registerForPush).toHaveBeenCalledTimes(1);
    jest.restoreAllMocks();
  });
});
