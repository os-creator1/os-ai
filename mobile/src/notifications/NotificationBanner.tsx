import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Linking, StyleSheet } from 'react-native';
import Constants from 'expo-constants';
import { useAuth } from '../auth/AuthContext';
import { Banner } from '../components';
import { space } from '../theme';
import { getPushPermission, requestPushPermission, type PushPermission } from './permission';
import { registerForPush } from './registerDevice';

/** Explains and fixes notification permission. Hidden when granted or when this device cannot receive push (simulator). */
export function NotificationBanner() {
  const { api } = useAuth();
  const [perm, setPerm] = useState<PushPermission>('unavailable');

  const last = useRef<PushPermission>('unavailable');

  // Every observed permission goes through here. When it BECOMES granted (via the prompt, or by the user flipping it in the
  // system Settings and coming back), this device is registered for push right then — not only at the next sign-in/launch.
  const apply = useCallback(async (next: PushPermission) => {
    const was = last.current;
    last.current = next;
    setPerm(next);
    if (next === 'granted' && (was === 'denied' || was === 'undetermined')) {
      await registerForPush(api, Constants.expoConfig?.version, { ask: false }).catch(() => undefined);
    }
  }, [api]);

  useEffect(() => {
    void getPushPermission().then(apply);
    // Returning from system Settings: re-read the permission.
    const sub = AppState.addEventListener?.('change', (state) => { if (state === 'active') void getPushPermission().then(apply); });
    return () => { sub?.remove?.(); };
  }, [apply]);

  const enable = useCallback(async () => { await apply(await requestPushPermission()); }, [apply]);

  if (perm === 'granted' || perm === 'unavailable') return null;
  return perm === 'denied' ? (
    <Banner
      testID="notification-banner"
      tone="warning"
      icon="bell-off"
      title="Notifications are turned off"
      message="You will not hear about new messages until you allow them in Settings."
      action={{ label: 'Open settings', onPress: () => { void Linking.openSettings(); } }}
      style={s.wrap}
    />
  ) : (
    <Banner
      testID="notification-banner"
      tone="promo"
      title="Get a ping for new messages"
      message="Turn on notifications so you never miss a lead."
      action={{ label: 'Turn on', onPress: () => { void enable(); } }}
      style={s.wrap}
    />
  );
}
const s = StyleSheet.create({
  wrap: { marginHorizontal: space.md, marginTop: 10, marginBottom: 6 },
});
