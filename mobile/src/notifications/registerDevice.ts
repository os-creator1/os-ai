import Constants from 'expo-constants';
import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import { Platform } from 'react-native';
import type { Api } from '../api/client';

/**
 * Push registration infrastructure (Phase 1: registers only, nothing is sent yet).
 * Asks for permission, obtains an Expo push token for this install and hands it to
 * PUT /devices/current. The token is an address, not a secret credential; the server stores it per device row
 * and drops it on logout. Returns the registered token or null (simulator, permission denied, no EAS project).
 */
export async function registerForPush(api: Api, appVersion?: string, opts: { ask?: boolean } = {}): Promise<string | null> {
  if (!Device.isDevice) return null;

  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', { name: 'Messages', importance: Notifications.AndroidImportance.HIGH });
  }

  let { status } = await Notifications.getPermissionsAsync();
  // Never prompt out of the blue at launch: asking is the user's explicit choice (see NotificationBanner).
  if (status !== 'granted' && opts.ask) status = (await Notifications.requestPermissionsAsync()).status;
  if (status !== 'granted') return null;

  const projectId = Constants.expoConfig?.extra?.eas?.projectId ?? Constants.easConfig?.projectId;
  if (!projectId) return null;

  const { data } = await Notifications.getExpoPushTokenAsync({ projectId });
  await api.registerPush('expo', data, appVersion);
  return data;
}
