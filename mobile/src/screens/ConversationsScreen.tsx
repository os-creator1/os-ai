import React, { useCallback, useEffect, useRef, useState } from 'react';
import { FlatList, Pressable, RefreshControl, StyleSheet, View } from 'react-native';
import type { CompositeScreenProps } from '@react-navigation/native';
import type { BottomTabScreenProps } from '@react-navigation/bottom-tabs';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ApiError } from '../api/client';
import type { ConversationSummary } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { Banner, Button, EmptyState, ErrorState, Icon, SearchField, SegmentedControl, SkeletonList, Text, UnreadDot, Avatar } from '../components';
import { listStamp } from '../format/time';
import { useOnline } from '../net/useOnline';
import { NotificationBanner } from '../notifications/NotificationBanner';
import { useRealtime } from '../realtime/RealtimeProvider';
import { colors, space } from '../theme';
import type { MainTabs, RootStack } from '../navigation/types';
import { useUnread } from '../navigation/unread';
import { useTopInset } from './ui';

type Props = CompositeScreenProps<BottomTabScreenProps<MainTabs, 'Conversations'>, NativeStackScreenProps<RootStack>>;
type Filter = 'all' | 'unread';

export function ConversationsScreen({ navigation }: Props) {
  const { api, scope, business, clearBusiness } = useAuth();
  const topInset = useTopInset();
  const online = useOnline();
  const { setHasUnread } = useUnread();
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
  // True while the latest full load has not finished (applied, failed or superseded).
  const loadInFlight = useRef(false);

  const load = useCallback(async (preserveItems = false) => {
    if (!scope) return;
    const current = ++generation.current;
    // Full loads and live refreshes share ONE ordering: whichever STARTED last may apply its result.
    const startedAs = ++refreshSeq.current;
    loadInFlight.current = true;
    fetchingMore.current = false;
    setLoadingMore(false);
    setMoreError(null);
    setError(null);
    setNextPage(null);
    if (!preserveItems) setItems(null);

    try {
      const res = await api.conversations(scope, { filter, search: search.trim() || undefined, page: 1 });
      if (generation.current !== current) return;
      // A live refresh started after this load owns the newer view; applying this older response would roll it back.
      if (startedAs !== refreshSeq.current) {
        // Keep pagination available: the superseding refresh may fail, and this response's page-1 meta is still valid.
        setNextPage((n) => n ?? res.meta.next_page);
        return;
      }
      setItems(res.data);
      setNextPage(res.meta.next_page);
    } catch (e) {
      if (generation.current === current) {
        setError(e instanceof Error ? e.message : 'Could not load conversations.');
      }
    } finally {
      if (generation.current === current) loadInFlight.current = false;
    }
  }, [api, scope, filter, search]);

  // Each Business, filter or search starts a fresh page sequence. A late reply
  // from an earlier selection cannot replace or append to the new Business list.
  useEffect(() => {
    void load();
    return () => { generation.current++; };
  }, [load]);

  const itemsRef = useRef<ConversationSummary[] | null>(null);
  useEffect(() => { itemsRef.current = items; }, [items]);
  const changedUids = useRef(new Set<string>());
  const needsFullReload = useRef(false);
  // Uids a refresh has taken but not yet APPLIED. A refresh superseded by a newer one discards its result, so the newer one
  // re-reconciles these too; they leave the set only when a refresh actually applies them.
  const unappliedUids = useRef(new Set<string>());
  // Live refreshes can overlap; only the most recently STARTED one may apply its result (an older, slower response must not
  // overwrite newer previews, ordering or unread flags).
  const refreshSeq = useRef(0);

  /**
   * Live update. Page 1 is re-read and folded in (changed conversations jump to the top in server order; older pages the user
   * loaded stay; nothing is shown twice). A conversation the event names that is loaded but NOT on page 1 — e.g. one on page 2
   * that was just read on another device, which does not change its sort key — is reconciled individually from the API rather
   * than assumed unchanged. A resync (reconnect / app resume) or a filtered/searched view simply reloads.
   */
  const refreshTop = useCallback(async () => {
    if (!scope) return;
    // Nothing shown yet and the initial (or filter/search) load is still running: its response may predate what the event
    // is about, so KEEP the signals and look again shortly, once that load has applied.
    if (itemsRef.current === null && loadInFlight.current) {
      if (debounce.current) clearTimeout(debounce.current);
      debounce.current = setTimeout(() => { void refreshTopRef.current(); }, 250);
      return;
    }
    changedUids.current.forEach((u) => unappliedUids.current.add(u));
    changedUids.current.clear();
    const uids = [...unappliedUids.current];
    if (needsFullReload.current || filter !== 'all' || search.trim() !== '') {
      needsFullReload.current = false;
      unappliedUids.current.clear(); // a full reload re-reads everything
      void load(true);
      return;
    }
    const current = generation.current;
    const mine = ++refreshSeq.current;
    try {
      const res = await api.conversations(scope, { filter, page: 1 });
      if (generation.current !== current || mine !== refreshSeq.current) return;
      // If page 1 shares nothing with what is loaded while more pages exist, so many conversations arrived at once that the
      // new window cannot be joined contiguously to the old results (some changed conversation may now sit on a page we
      // would skip). Restart from the top instead of splicing.
      const loadedNow = itemsRef.current ?? [];
      if (loadedNow.length > 0 && res.meta.next_page !== null && !res.data.some((c) => loadedNow.some((l) => l.uid === c.uid))) {
        unappliedUids.current.clear();
        void load(true);
        return;
      }
      const onPageOne = new Set(res.data.map((c) => c.uid));
      const patches = new Map<string, ConversationSummary | null>();
      for (const uid of uids) {
        if (onPageOne.has(uid) || !itemsRef.current?.some((c) => c.uid === uid)) continue;
        try {
          patches.set(uid, (await api.conversation(scope, uid)).conversation);
        } catch (e) {
          // No longer reachable (moved Location, access lost): drop it from the list.
          if (e instanceof ApiError && e.status === 404) patches.set(uid, null);
        }
      }
      if (generation.current !== current || mine !== refreshSeq.current) return;
      uids.forEach((u) => unappliedUids.current.delete(u));
      setItems((previous) => {
        if (previous === null) return res.data;
        const rest = previous
          .filter((c) => !onPageOne.has(c.uid))
          .flatMap((c) => (patches.has(c.uid) ? (patches.get(c.uid) ? [patches.get(c.uid) as ConversationSummary] : []) : [c]));
        return [...res.data, ...rest];
      });
      setNextPage((n) => n ?? res.meta.next_page);
    } catch { /* silent: the next event or pull-to-refresh retries */ }
  }, [api, scope, filter, search, load]);

  const refreshTopRef = useRef(refreshTop);
  refreshTopRef.current = refreshTop;
  const debounce = useRef<ReturnType<typeof setTimeout> | null>(null);
  useRealtime((e) => {
    if (e.kind === 'change') changedUids.current.add(e.conversation_uid);
    else needsFullReload.current = true;
    if (debounce.current) clearTimeout(debounce.current);
    debounce.current = setTimeout(() => { void refreshTop(); }, 250);
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

  // Tab-bar dot: only the unfiltered list (or the Unread filter itself) says anything about unread conversations.
  useEffect(() => {
    if (items === null || search.trim() !== '') return;
    if (filter === 'unread') setHasUnread(items.length > 0);
    else setHasUnread(items.some((c) => c.unread));
  }, [items, filter, search, setHasUnread]);

  if (!scope) return null;

  const query = search.trim();
  const open = (item: ConversationSummary) =>
    navigation.navigate('ConversationDetail', { uid: item.uid, title: item.display_name ?? item.phone ?? 'Conversation' });

  const empty = items === null
    ? error
      ? <ErrorState title="Conversations didn't load" message={error} retryLabel="Retry" onRetry={() => { void load(); }} />
      : <SkeletonList />
    : query !== ''
      ? <EmptyState icon="search" tone="neutral" title="No results" message={`Nothing matches “${query}”. Try a name, a number or words from a message.`}
          action={{ label: 'Clear search', onPress: () => setSearch('') }} />
      : filter === 'unread'
        ? <EmptyState icon="check" title="You're all caught up" message="No unread conversations. New messages land here."
            action={{ label: 'Show all conversations', onPress: () => setFilter('all') }} />
        : <EmptyState icon="message-circle" title="No conversations yet" message="When a customer texts this business, the conversation shows up here." />;

  // Keep search and filter controls mounted while a new query is loading:
  // replacing the entire screen here would blur the TextInput on every key.
  return (
    <View style={s.wrap}>
      {!online ? (
        <Banner testID="offline-banner" tone="dark" message="You're offline. Showing the conversations already loaded." style={[s.offline, { top: topInset + 6 }]} />
      ) : null}
      <View style={[s.top, { paddingTop: topInset + 8 }]}>
        <View style={s.switchRow}>
          <Pressable accessibilityRole="button" accessibilityLabel={`Switch business, current: ${business?.name ?? ''}`} hitSlop={6}
            onPress={() => { void clearBusiness(); }} style={s.switcher}>
            <Text size={15} weight="600" color={colors.textTertiary} numberOfLines={1} style={s.switcherText}>{business?.name ?? 'Business'}</Text>
            <Icon name="chevron-down" size={16} color={colors.textTertiary} />
          </Pressable>
        </View>
        <Text variant="largeTitle" accessibilityRole="header" style={s.title}>Conversations</Text>
        <SearchField placeholder="Search names, numbers, messages" value={search} onChangeText={setSearch} />
        <SegmentedControl
          options={[{ value: 'all', label: 'All' }, { value: 'unread', label: 'Unread' }]}
          value={filter}
          onChange={setFilter}
        />
      </View>
      <NotificationBanner />
      <FlatList
        testID="conversation-list"
        style={s.list}
        contentContainerStyle={items !== null && items.length === 0 ? s.grow : undefined}
        data={items ?? []}
        keyExtractor={(c) => c.uid}
        keyboardShouldPersistTaps="handled"
        onEndReached={() => { void loadMore(); }}
        onEndReachedThreshold={0.3}
        refreshControl={<RefreshControl tintColor={colors.brand} colors={[colors.brand]} refreshing={refreshing} onRefresh={async () => { setRefreshing(true); await load(true); setRefreshing(false); }} />}
        ListEmptyComponent={<View style={s.grow}>{empty}</View>}
        ListFooterComponent={nextPage !== null ? (
          loadingMore ? <Text size={14} color={colors.textSecondary} align="center" style={s.more}>Loading older conversations…</Text>
            : <View style={s.footer}>
                {moreError ? <Text size={14} color={colors.danger} align="center">{moreError}</Text> : null}
                <Button title={moreError ? 'Retry older leads' : 'Load older leads'} variant="plain" compact onPress={() => { void loadMore(); }} />
              </View>
        ) : undefined}
        renderItem={({ item }) => <ConversationRow item={item} onPress={() => open(item)} />}
      />
    </View>
  );
}

/** One conversation: avatar, name + time, preview + unread dot. Unread rows are bolder and the time turns brand. */
function ConversationRow({ item, onPress }: { item: ConversationSummary; onPress: () => void }) {
  const name = item.display_name ?? item.phone ?? 'Unknown';
  const preview = item.last_message
    ? `${item.last_message.direction === 'outbound' ? 'You: ' : ''}${item.last_message.preview}`
    : '';
  const stamp = listStamp(item.last_message?.at ?? item.updated_at);
  const u = item.unread;
  return (
    <Pressable accessibilityRole="button" accessibilityHint={u ? 'Unread conversation' : undefined}
      onPress={onPress} style={({ pressed }) => [s.row, pressed && s.rowPressed]}>
      <Avatar name={item.display_name} />
      <View style={s.rowMain}>
        <View style={s.line}>
          <Text size={16} weight={u ? '800' : '600'} numberOfLines={1} style={s.flex}>{name}</Text>
          {stamp ? <Text size={13} weight={u ? '700' : '400'} color={u ? colors.brandPressed : colors.textSecondary}>{stamp}</Text> : null}
        </View>
        <View style={s.line}>
          <Text size={14.5} weight={u ? '500' : '400'} color={u ? colors.textPrimary : colors.textSecondary} numberOfLines={1} style={s.flex}>{preview}</Text>
          {u ? <UnreadDot /> : null}
        </View>
      </View>
    </Pressable>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.surface },
  top: { paddingHorizontal: space.md, paddingBottom: space.sm, gap: space.mid, backgroundColor: colors.surface },
  switchRow: { flexDirection: 'row', alignItems: 'center', minHeight: 44 },
  switcher: { flexDirection: 'row', alignItems: 'center', gap: 6, flexShrink: 1, minHeight: 44, paddingHorizontal: 4 },
  switcherText: { flexShrink: 1 },
  title: { marginTop: -10 },
  offline: { position: 'absolute', left: 12, right: 12, zIndex: 2, paddingVertical: 12, alignItems: 'center' },
  list: { flex: 1, backgroundColor: colors.surface },
  grow: { flexGrow: 1 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 76, paddingLeft: space.md, backgroundColor: colors.surface },
  rowPressed: { backgroundColor: colors.background },
  rowMain: { flex: 1, minWidth: 0, gap: 3, paddingVertical: 14, paddingRight: space.md, borderBottomWidth: 1, borderBottomColor: colors.divider },
  line: { flexDirection: 'row', alignItems: 'center', gap: 6 },
  flex: { flex: 1, minWidth: 0 },
  more: { padding: space.md },
  footer: { alignItems: 'center', padding: space.sm, gap: space.xs },
});
