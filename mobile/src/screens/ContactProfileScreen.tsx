import React, { useCallback, useEffect, useState } from 'react';
import { ScrollView, StyleSheet, Text, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import type { ContactProfile } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import type { RootStack } from '../navigation/types';
import { colors, space } from '../theme';
import { ErrorState, Loading, useBottomInset } from './ui';

type Props = NativeStackScreenProps<RootStack, 'ContactProfile'>;

export function ContactProfileScreen({ route }: Props) {
  const { api, scope } = useAuth();
  const bottomInset = useBottomInset();
  const [c, setC] = useState<ContactProfile | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!scope) return;
    setError(null);
    try { setC(await api.contact(scope, route.params.uid)); }
    catch (e) { setError(e instanceof Error ? e.message : 'Could not load this contact.'); }
  }, [api, scope, route.params.uid]);
  useEffect(() => { void load(); }, [load]);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!c) return <Loading />;

  return (
    <ScrollView style={s.wrap} contentContainerStyle={{ padding: space.md, paddingBottom: space.md + bottomInset }}>
      <Text style={s.name}>{c.display_name ?? c.phone}</Text>
      <Text style={s.sub}>{c.phone}</Text>
      {c.email ? <Text style={s.sub}>{c.email}</Text> : null}
      {!c.subscribed ? <Text style={s.warn}>Opted out of messages</Text> : null}
      <View style={{ marginTop: space.md }}>
        {c.fields.map((f) => (
          <View key={f.tag} style={s.field}>
            <Text style={s.label}>{f.label}</Text>
            <Text>{f.value ?? '—'}</Text>
          </View>
        ))}
      </View>
    </ScrollView>
  );
}
const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.bg },
  name: { fontSize: 24, fontWeight: '700', color: colors.text },
  sub: { color: colors.muted, marginTop: 4 },
  warn: { color: colors.danger, marginTop: space.sm },
  field: { paddingVertical: space.sm, borderBottomColor: colors.line, borderBottomWidth: StyleSheet.hairlineWidth },
  label: { color: colors.muted, fontSize: 12 },
});
