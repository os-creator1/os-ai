import React, { useEffect, useState } from 'react';
import { StyleSheet, Text, View } from 'react-native';
import type { CallingCapability } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { colors, space } from '../theme';
import { Loading } from './ui';

/** Placeholder: shows whether calling WOULD be possible for this person here. No call is placed, no provider contacted. */
export function CallsScreen() {
  const { api, scope } = useAuth();
  const [cap, setCap] = useState<CallingCapability | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!scope) return;
    api.callingCapability(scope).then(setCap).catch(() => setFailed(true));
  }, [api, scope]);

  if (!cap && !failed) return <Loading />;
  return (
    <View style={s.wrap}>
      <Text style={s.title}>Calls</Text>
      <Text style={s.body}>Voice calling is coming in the next milestone.</Text>
      <Text style={s.muted}>
        {failed ? 'Could not check calling status.' : cap?.available ? 'Calling is set up for this business.' : `Not available yet: ${cap?.reason ?? 'unknown reason'}`}
      </Text>
    </View>
  );
}
const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.bg, padding: space.lg, justifyContent: 'center' },
  title: { fontSize: 24, fontWeight: '700', marginBottom: space.sm, color: colors.text },
  body: { fontSize: 16, color: colors.text },
  muted: { color: colors.muted, marginTop: space.md },
});
