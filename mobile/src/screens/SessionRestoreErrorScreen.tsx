import React, { useState } from 'react';
import { StyleSheet, View } from 'react-native';
import { BrandMark, Button, Icon, Text } from '../components';
import { colors, space } from '../theme';
import { useBottomInset } from './ui';

type Props = { message: string; onRetry: () => Promise<void>; onSignInAgain: () => Promise<void> };

/** Shown when a saved session could not be checked at launch (offline, server down). The saved sign-in is kept. */
export function SessionRestoreErrorScreen({ message, onRetry, onSignInAgain }: Props) {
  const bottomInset = useBottomInset();
  const [retrying, setRetrying] = useState(false);
  return (
    <View testID="session-restore-error" style={s.wrap}>
      <View style={s.body}>
        <BrandMark size={52} />
        <View style={s.circle}><Icon name="cloud-off" size={26} color={colors.warning} /></View>
        <Text size={21} weight="800" align="center" accessibilityRole="header">We couldn't load your account</Text>
        <Text size={15.5} color={colors.textSecondary} align="center" style={s.msg} accessibilityRole="alert">{message}</Text>
        <Text size={15.5} color={colors.textSecondary} align="center" style={s.msg}>Your messages are safe.</Text>
      </View>
      <View style={[s.actions, { paddingBottom: space.md + bottomInset }]}>
        <Button title="Try again" icon="rotate-ccw" fullWidth loading={retrying}
          onPress={() => { setRetrying(true); void onRetry().finally(() => setRetrying(false)); }} />
        <Button title="Sign in again" variant="plain" fullWidth onPress={() => { void onSignInAgain(); }} />
      </View>
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.background },
  body: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 14, paddingHorizontal: 36 },
  circle: { width: 64, height: 64, borderRadius: 32, backgroundColor: colors.warningSoft, alignItems: 'center', justifyContent: 'center', marginTop: space.mid },
  msg: { lineHeight: 22 },
  actions: { paddingHorizontal: space.md, paddingTop: space.mid, gap: space.sm },
});
