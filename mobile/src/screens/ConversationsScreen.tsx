import React, { useCallback, useEffect, useRef, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import type { ConversationSummary } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { NotificationBanner } from '../notifications/NotificationBanner';
import { useRealtime } from '../realtime/RealtimeProvider';
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
  const [nextPage, setNextPage] = useState<number | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [moreError, setMoreError] = useState<string | null>(null);
  const generation = useRef(0);
  const fetchingMore = useRef(false);

  const load = useCallback(async (preserveItems = false) => {
    if (!scope) return;
    const current = ++generation.current;
    fetchingMore.current = false;
    setLoadingMore(false);
    setMoreError(null);
    setError(null);
    setNextPage(null);
    if (!preserveItems) setItems(null);

    try {
      const res = await api.conversations(scope, { filter, search: search.trim() || undefined, page: 1 });
      if (generation.current !== current) return;
      setItems(res.data);
      setNextPage(res.meta.next_page);
    } catch (e) {
      if (generation.current === current) {
        setError(e instanceof Error ? e.message : 'Could not load conversations.');
      }
    }
  }, [api, scope, filter, search]);

  // Each Business, filter or search starts a fresh page sequence. A late reply
  // from an earlier selection cannot replace or append to the new Business list.
  useEffect(() => {
    void load();
    return () => { generation.current++; };
  }, [load]);

  /**
   * Live update: fold page 1 into what is already on screen. Conversations that moved or changed (new message, unread,
   * delivery state) jump to the top in server order; older pages the user loaded stay; nothing is shown twice.
   * Under the Unread filter a read/unread change can add or remove rows, so that view simply reloads.
   */
  const refreshTop = useCallback(async () => {
    if (!scope) return;
    if (filter !== 'all' || search.trim() !== '') { void load(true); return; }
    const current = generation.current;
    try {
      const res = await api.conversations(scope, { filter, page: 1 });
      if (generation.current !== current) return;
      setItems((previous) => {
        if (previous === null) return res.data;
        const fresh = new Set(res.data.map((c) => c.uid));
        return [...res.data, ...previous.filter((c) => !fresh.has(c.uid))];
      });
      setNextPage((n) => n ?? res.meta.next_page);
    } catch { /* silent: the next event or pull-to-refresh retries */ }
  }, [api, scope, filter, search, load]);

  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  useRealtime((e) => {
    if (e.kind === 'change' || e.kind === 'resync') {
      if (debounce.current) clearTimeout(debounce.current);
      debounce.current = setTimeout(() => { void refreshTop(); }, 250);
    }
  });
  useEffect(() => () => { if (debounce.current) clearTimeout(debounce.current); }, []);

  const loadMore = useCallback(async () => {
    if (!scope || nextPage === null || items === null || refreshing || fetchingMore.current) return;
    fetchingMore.current = true;
    setLoadingMore(true);
    setMoreError(null);
    const current = generation.current;
    const page = nextPage;

    try {
      const res = await api.conversations(scope, { filter, search: search.trim() || undefined, page });
      if (current !== generation.current) return;
      setItems((previous) => {
        if (previous === null) return res.data;
        // A lead can move between pages when a new message arrives. Never
        // display it twice while retaining all other older conversations.
        const seen = new Set(previous.map((item) => item.uid));
        return [...previous, ...res.data.filter((item) => !seen.has(item.uid))];
      });
      setNextPage(res.meta.next_page === page ? null : res.meta.next_page);
    } catch (e) {
      if (current === generation.current) {
        setMoreError(e instanceof Error ? e.message : 'Could not load older conversations.');
      }
    } finally {
      if (current === generation.current) {
        fetchingMore.current = false;
        setLoadingMore(false);
      }
    }
  }, [api, scope, filter, search, nextPage, items, refreshing]);

  useEffect(() => {
    navigation.setOptions({ title: business?.name ?? 'Conversations' });
  }, [navigation, business]);

  if (!scope) return null;

  // Keep search and filter controls mounted while a new query is loading:
  // replacing the entire screen here would blur the TextInput on every key.
  return (
    <View style={s.wrap}>
      <NotificationBanner />
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
        testID="conversation-list"
        data={items ?? []}
        keyExtractor={(c) => c.uid}
        onEndReached={() => { void loadMore(); }}
        onEndReachedThreshold={0.3}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(true); setRefreshing(false); }} />}
        ListEmptyComponent={
          items === null
            ? error
              ? <ErrorState message={error} onRetry={() => { void load(); }} />
              : <Loading />
            : <Text style={s.empty}>No conversations yet.</Text>
        }
        ListFooterComponent={nextPage !== null ? (
          loadingMore ? <Text style={s.empty}>Loading older conversations…</Text>
            : <View style={s.footer}>
                {moreError ? <Text style={s.preview}>{moreError}</Text> : null}
                <Button title={moreError ? 'Retry older leads' : 'Load older leads'} variant="plain" onPress={() => { void loadMore(); }} />
              </View>
        ) : undefined}
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
