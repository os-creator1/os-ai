import React, { useEffect, useState } from 'react';
import { Linking, Pressable, StyleSheet, View } from 'react-native';
import type { CallingCapability } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { EmptyState, Icon, LargeTitle, Text } from '../components';
import { apiBaseUrl } from '../config';
import { colors, space } from '../theme';
import { useTopInset } from './ui';

/** Placeholder: shows whether calling WOULD be possible for this person here. No call is placed, no provider contacted. */
export function CallsScreen() {
  const { api, scope } = useAuth();
  const topInset = useTopInset();
  const [cap, setCap] = useState<CallingCapability | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!scope) return;
    api.callingCapability(scope).then(setCap).catch(() => setFailed(true));
  }, [api, scope]);

  const web = apiBaseUrl();
  const status = failed
    ? 'Could not check calling status.'
    : cap === null ? null
      : cap.available ? 'Calling is set up for this business.' : `Not available yet: ${cap.reason ?? 'unknown reason'}`;

  return (
    <View testID="calls-screen" style={[s.wrap, { paddingTop: topInset + space.sm }]}>
      <View style={s.titleRow}><LargeTitle>Calls</LargeTitle></View>
      <EmptyState icon="phone" title="Calling is coming to the app" message="For now, call from MotionGrove on the web. We'll let you know when it's ready here.">
        {web ? (
          <Pressable accessibilityRole="link" onPress={() => { void Linking.openURL(web); }} style={s.link} hitSlop={6}>
            <Text size={15} weight="700" color={colors.brandPressed}>Open MotionGrove on the web</Text>
            <Icon name="external-link" size={15} color={colors.brandPressed} />
          </Pressable>
        ) : null}
        {status ? <Text size={13} color={colors.textSecondary} align="center">{status}</Text> : null}
      </EmptyState>
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.background },
  titleRow: { minHeight: 44, justifyContent: 'center', paddingHorizontal: space.md },
  link: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 44, marginTop: space.sm },
});
