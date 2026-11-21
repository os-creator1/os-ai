import React, { useCallback, useEffect, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import type { ConversationSummary } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { colors, space } from '../theme';
import type { RootStack } from '../navigation/types';
import { Button, ErrorState, Loading } from './ui';

type Props = NativeStackScreenProps<RootStack, 'Conversations'>;
type Filter = 'all' | 'unread';

export function ConversationsScreen({ navigation }: Props) {
  const { api, scope, business, clearBusiness, signOut } = useAuth();
  const [items, setItems] = useState<ConversationSummary[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [filter, setFilter] = useState<Filter>('all');
  const [search, setSearch] = useState('');

  const load = useCallback(async () => {
    if (!scope) return;
    setError(null);
    try {
      const res = await api.conversations(scope, { filter, search: search.trim() || undefined });
      setItems(res.data);
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not load conversations.');
    }
  }, [api, scope, filter, search]);

  useEffect(() => { void load(); }, [load]);

  useEffect(() => {
    navigation.setOptions({ title: business?.name ?? 'Conversations' });
  }, [navigation, business]);

  if (!scope) return null;
  if (error && items === null) return <ErrorState message={error} onRetry={load} />;
  if (items === null) return <Loading />;

  return (
    <View style={s.wrap}>
      <TextInput style={s.search} placeholder="Search name or number" accessibilityLabel="Search" value={search}
        onChangeText={setSearch} autoCapitalize="none" returnKeyType="search" />
      <View style={s.tabs}>
        {(['all', 'unread'] as Filter[]).map((f) => (
          <Pressable key={f} accessibilityRole="tab" accessibilityState={{ selected: filter === f }} onPress={() => setFilter(f)}
            style={[s.tab, filter === f && s.tabOn]}>
            <Text style={filter === f ? s.tabOnText : s.tabText}>{f === 'all' ? 'All' : 'Unread'}</Text>
          </Pressable>
        ))}
        <Button title="Calls" variant="plain" onPress={() => navigation.navigate('Calls')} />
      </View>
      <FlatList
        data={items}
        keyExtractor={(c) => c.uid}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }} />}
        ListEmptyComponent={<Text style={s.empty}>No conversations yet.</Text>}
        renderItem={({ item }) => (
          <Pressable accessibilityRole="button" style={s.row} onPress={() => navigation.navigate('ConversationDetail', { uid: item.uid, title: item.display_name ?? item.phone ?? 'Conversation' })}>
            <View style={{ flex: 1 }}>
              <Text style={[s.name, item.unread && s.bold]} numberOfLines={1}>{item.display_name ?? item.phone ?? 'Unknown'}</Text>
              <Text style={s.preview} numberOfLines={1}>{item.last_message?.preview ?? ''}</Text>
            </View>
            {item.unread ? <View accessibilityLabel="Unread" style={s.dot} /> : null}
          </Pressable>
        )}
      />
      <View style={s.footer}>
        <Button title="Switch business" variant="plain" onPress={() => void clearBusiness()} />
        <Button title="Sign out" variant="plain" onPress={signOut} />
      </View>
    </View>
  );
}
const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.bg },
  search: { margin: space.md, backgroundColor: colors.card, borderRadius: 10, borderColor: colors.line, borderWidth: 1, padding: 10 },
  tabs: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.md, gap: space.sm },
  tab: { paddingVertical: 6, paddingHorizontal: 14, borderRadius: 16, borderColor: colors.line, borderWidth: 1 },
  tabOn: { backgroundColor: colors.brand, borderColor: colors.brand },
  tabText: { color: colors.text }, tabOnText: { color: colors.brandText, fontWeight: '600' },
  row: { flexDirection: 'row', alignItems: 'center', padding: space.md, borderBottomColor: colors.line, borderBottomWidth: StyleSheet.hairlineWidth, backgroundColor: colors.card },
  name: { fontSize: 16, color: colors.text }, bold: { fontWeight: '700' },
  preview: { color: colors.muted, marginTop: 2 },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.unread },
  empty: { textAlign: 'center', color: colors.muted, marginTop: space.lg },
  footer: { flexDirection: 'row', justifyContent: 'space-between', padding: space.sm },
});
