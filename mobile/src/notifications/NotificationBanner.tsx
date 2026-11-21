import React, { useCallback, useEffect, useState } from 'react';
import { Linking, StyleSheet, Text, View } from 'react-native';
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

  useEffect(() => { void getPushPermission().then(setPerm); }, []);

  const enable = useCallback(async () => {
    const next = await requestPushPermission();
    setPerm(next);
    if (next === 'granted') await registerForPush(api, Constants.expoConfig?.version, { ask: false }).catch(() => undefined);
  }, [api]);

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
