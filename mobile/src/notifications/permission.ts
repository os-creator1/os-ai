import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';

export type PushPermission = 'granted' | 'denied' | 'undetermined' | 'unavailable';

/** Current OS notification permission. 'unavailable' on simulators/emulators without push and when the module is absent. */
export async function getPushPermission(): Promise<PushPermission> {
  try {
    if (!Device.isDevice || typeof Notifications.getPermissionsAsync !== 'function') return 'unavailable';
    const { status } = await Notifications.getPermissionsAsync();
    return status === 'granted' ? 'granted' : status === 'denied' ? 'denied' : 'undetermined';
  } catch {
    return 'unavailable';
  }
}

export async function requestPushPermission(): Promise<PushPermission> {
  try {
    if (!Device.isDevice || typeof Notifications.requestPermissionsAsync !== 'function') return 'unavailable';
    const { status } = await Notifications.requestPermissionsAsync();
    return status === 'granted' ? 'granted' : 'denied';
  } catch {
    return 'unavailable';
  }
}
