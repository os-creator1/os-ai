import React, { useCallback, useEffect, useState } from 'react';
import { FlatList, StyleSheet, Text, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import type { ConversationDetail } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import type { RootStack } from '../navigation/types';
import { colors, space } from '../theme';
import { Button, ErrorState, Loading } from './ui';

type Props = NativeStackScreenProps<RootStack, 'ConversationDetail'>;

export function ConversationDetailScreen({ route, navigation }: Props) {
  const { api, scope } = useAuth();
  const [data, setData] = useState<ConversationDetail | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!scope) return;
    setError(null);
    try { setData(await api.conversation(scope, route.params.uid)); }
    catch (e) { setError(e instanceof Error ? e.message : 'Could not load this conversation.'); }
  }, [api, scope, route.params.uid]);

  useEffect(() => { void load(); }, [load]);

  if (error) return <ErrorState message={error} onRetry={load} />;
  if (!data) return <Loading />;

  return (
    <View style={s.wrap}>
      {data.contact_uid ? (
        <Button title="Contact profile" variant="plain" onPress={() => navigation.navigate('ContactProfile', { uid: data.contact_uid! })} />
      ) : null}
      <FlatList
        data={data.messages}
        keyExtractor={(m) => String(m.id)}
        contentContainerStyle={{ padding: space.md }}
        renderItem={({ item }) => (
          <View style={[s.bubble, item.direction === 'outbound' ? s.out : s.in]}>
            <Text style={item.direction === 'outbound' ? s.outText : s.inText}>{item.body ?? (item.media_url ? '[media]' : '')}</Text>
            <Text style={s.time}>{new Date(item.created_at).toLocaleString()}</Text>
          </View>
        )}
      />
      <Text style={s.note}>Replying from the app arrives in the next milestone.</Text>
    </View>
  );
}
const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.bg },
  bubble: { maxWidth: '82%', padding: 10, borderRadius: 14, marginBottom: space.sm },
  in: { alignSelf: 'flex-start', backgroundColor: colors.card, borderColor: colors.line, borderWidth: 1 },
  out: { alignSelf: 'flex-end', backgroundColor: colors.brand },
  inText: { color: colors.text }, outText: { color: colors.brandText },
  time: { fontSize: 11, color: colors.muted, marginTop: 4 },
  note: { textAlign: 'center', color: colors.muted, padding: space.sm },
});
