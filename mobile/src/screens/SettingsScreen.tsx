import React, { useCallback, useEffect, useState } from 'react';
import { AppState, Linking, ScrollView, StyleSheet, View } from 'react-native';
import Constants from 'expo-constants';
import { useAuth } from '../auth/AuthContext';
import { Avatar, Button, LargeTitle, ListGroup, ListRow, Pill, SectionHeader, Text } from '../components';
import { getPushPermission, type PushPermission } from '../notifications/permission';
import { colors, space } from '../theme';
import { useTopInset } from './ui';

const PUSH_PILL: Record<PushPermission, { label: string; tone: 'success' | 'warning' | 'neutral' }> = {
  granted: { label: 'On', tone: 'success' },
  denied: { label: 'Off', tone: 'warning' },
  undetermined: { label: 'Off', tone: 'warning' },
  unavailable: { label: 'Not available', tone: 'neutral' },
};

/** Account, current Business (switch), notification status, app version and sign out. */
export function SettingsScreen() {
  const { me, business, clearBusiness, signOut } = useAuth();
  const topInset = useTopInset();
  const [push, setPush] = useState<PushPermission | null>(null);
  const [signingOut, setSigningOut] = useState(false);

  const readPush = useCallback(() => { void getPushPermission().then(setPush).catch(() => setPush('unavailable')); }, []);
  useEffect(() => {
    readPush();
    // Coming back from the phone's Settings: show the permission as it is now.
    const sub = AppState.addEventListener?.('change', (state) => { if (state === 'active') readPush(); });
    return () => { sub?.remove?.(); };
  }, [readPush]);

  const version = Constants.expoConfig?.version ?? '';
  const pill = push ? PUSH_PILL[push] : null;

  return (
    <ScrollView testID="settings-screen" style={s.wrap} contentContainerStyle={[s.content, { paddingTop: topInset + space.sm }]}>
      <View style={s.titleRow}><LargeTitle>Settings</LargeTitle></View>

      <ListGroup>
        <ListRow
          minHeight={76}
          leading={<Avatar name={me?.user.name} />}
          title={me?.user.name ?? ''}
          strong
          subtitle={me?.user.email}
        />
      </ListGroup>

      <View style={s.section}>
        <SectionHeader title="Business" />
        <ListGroup>
          <ListRow icon={{ name: 'grid', bg: colors.brandSoft, fg: colors.brandPressed }} title={business?.name ?? ''} subtitle={business?.workspace_name} />
          <ListRow icon={{ name: 'repeat' }} title="Switch business" accent chevron onPress={() => { void clearBusiness(); }} />
        </ListGroup>
      </View>

      <View style={s.section}>
        <SectionHeader title="Notifications" />
        <ListGroup>
          <ListRow
            icon={{ name: 'bell', bg: colors.warningSoft, fg: colors.warning }}
            title="Push notifications"
            accessibilityLabel={pill ? `Push notifications: ${pill.label}` : 'Push notifications'}
            trailing={pill ? <Pill label={pill.label} tone={pill.tone} /> : null}
          />
          <ListRow icon={{ name: 'external-link' }} title="Open phone settings" accent chevron onPress={() => { void Linking.openSettings(); }} />
        </ListGroup>
      </View>

      <View style={s.section}>
        <SectionHeader title="App" />
        <ListGroup>
          <ListRow title="Version" value={version} />
        </ListGroup>
      </View>

      <Button
        title="Sign out"
        icon="log-out"
        variant="destructive"
        fullWidth
        loading={signingOut}
        onPress={() => { setSigningOut(true); void signOut().finally(() => setSigningOut(false)); }}
      />
      <Text size={12.5} color={colors.textSecondary} align="center">Signed in as {me?.user.email ?? ''}</Text>
    </ScrollView>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.background },
  content: { paddingHorizontal: space.md, paddingBottom: space.lg, gap: 20 },
  titleRow: { minHeight: 44, justifyContent: 'center' },
  section: { gap: space.sm },
});
