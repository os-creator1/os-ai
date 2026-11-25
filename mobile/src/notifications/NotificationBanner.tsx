import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AppState, Linking, StyleSheet, Text, View } from 'react-native';
import Constants from 'expo-constants';
import { useAuth } from '../auth/AuthContext';
import { colors, space } from '../theme';
import { Button } from '../screens/ui';
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
  return (
    <View style={s.wrap} accessibilityRole="alert">
      <Text style={s.text}>{perm === 'denied' ? 'Notifications are turned off, so you will not hear about new messages.' : 'Get notified when a customer texts you.'}</Text>
      {perm === 'denied'
        ? <Button title="Open settings" variant="plain" onPress={() => { void Linking.openSettings(); }} />
        : <Button title="Turn on notifications" variant="plain" onPress={() => { void enable(); }} />}
    </View>
  );
}
const s = StyleSheet.create({
  wrap: { marginHorizontal: space.md, marginBottom: space.sm, padding: space.sm, borderRadius: 10, backgroundColor: colors.card, borderColor: colors.line, borderWidth: 1 },
  text: { color: colors.text },
});
