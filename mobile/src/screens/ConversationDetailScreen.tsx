import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Platform, Pressable, StyleSheet, TextInput, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ApiError } from '../api/client';
import type { ConversationDetail, Message } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { isFailed, statusLabel } from '../conversations/status';
import { uuidv4 } from '../conversations/uuid';
import { setActiveConversation } from '../notifications/target';
import { useRealtime } from '../realtime/RealtimeProvider';
import type { RootStack } from '../navigation/types';
import { Button, ErrorState, Icon, IconButton, Text } from '../components';
import { clockTime, dayLabel, sameDay } from '../format/time';
import { colors, fontFamilyFor, space } from '../theme';
import { Loading, useBottomInset, useTopInset } from './ui';

type Props = NativeStackScreenProps<RootStack, 'ConversationDetail'>;

/** A message the user sent that the server has not confirmed yet (or that failed). `key` is its idempotency key. */
type Pending = {
  key: string;
  body: string;
  state: 'sending' | 'failed';
  error?: string;
  /** retry = server recorded a failed bubble (use /retry); reply = nothing recorded, resend the SAME key. */
  mode: 'reply' | 'retry';
  canRetry: boolean;
};
type Row = (Message & { pending?: false }) | { pending: true; key: string; body: string; state: Pending['state']; error?: string; canRetry: boolean; mode: Pending['mode'] };
type RetryAttempt = { state: 'sending' | 'failed' | 'unconfirmed'; error?: string };
/** A list entry: a message (sent or pending) or a day separator. */
type Item = Row | { day: true; key: string; label: string };

/**
 * Keyed by conversation: opening another conversation (a notification tap while one is already open reuses the route and only
 * changes its params) mounts a FRESH thread — draft, pending/failed sends, retry state and loaded history never carry over,
 * so a failed message from one customer can never be retried into another's conversation.
 */
export function ConversationDetailScreen(props: Props) {
  return <ConversationThread key={props.route.params.uid} {...props} />;
}

function ConversationThread({ route, navigation }: Props) {
  const { api, scope } = useAuth();
  const bottomInset = useBottomInset();
  const topInset = useTopInset();
  const uid = route.params.uid;
  const [data, setData] = useState<ConversationDetail | null>(null);
  const [older, setOlder] = useState<Message[]>([]);
  const [hasMore, setHasMore] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [text, setText] = useState('');
  const [pending, setPending] = useState<Pending[]>([]);
  // An older failed bubble is already in server history, not in pending.
  // Keep its attempted retry/error state separately so refusals are visible.
  const [retryAttempts, setRetryAttempts] = useState<Record<string, RetryAttempt>>({});
  const inflight = useRef(new Set<string>());
  const listRef = useRef<FlatList<Item>>(null);
  const stick = useRef(true);
  const readSent = useRef(false);
  const dataRef = useRef<ConversationDetail | null>(null);
  // Live refreshes can overlap; only the most recently STARTED one may apply its result.
  const liveSeq = useRef(0);
  // Is this screen the one the user is looking at? A screen kept mounted under another one (Contact profile) is not.
  const focused = useRef(true);
  const earlierInFlight = useRef(false);
  const [loadingEarlier, setLoadingEarlier] = useState(false);
  const threadGeneration = useRef(0);
  // A live signal that arrived before the first load applied: that load's snapshot may predate it, so re-read right after.
  const liveWanted = useRef(false);
  const refreshLiveRef = useRef<() => Promise<void>>(async () => undefined);
  // Mirror of the loaded history pages, and a sequence so only the latest reconcile of them applies.
  const olderRef = useRef<Message[]>([]);
  olderRef.current = older;
  const olderSeq = useRef(0);

  const load = useCallback(async (): Promise<boolean | 'superseded'> => {
    if (!scope) return false;
    const version = ++threadGeneration.current;
    // Full loads and live refreshes share ONE ordering: whichever STARTED last may apply its result.
    const startedAs = ++liveSeq.current;
    earlierInFlight.current = false;
    setLoadingEarlier(false);
    setError(null);
    try {
      const d = await api.conversation(scope, uid);
      if (version !== threadGeneration.current) return false;
      // A live refresh started after this load and owns the newer view; applying this older response would roll it back.
      // Reported distinctly (not as a failure) so a send keeps its pending bubble until a refresh has actually confirmed it —
      // the live refresh clears it itself once the server history contains the message.
      if (startedAs !== liveSeq.current) return 'superseded';
      setData(d);
      dataRef.current = d;
      setHasMore(d.has_more);
      setOlder([]);
      if (liveWanted.current) { liveWanted.current = false; void refreshLiveRef.current(); }
      // Only the actual server history proves a previously unconfirmed
      // send/retry now has a persisted bubble. Once its send_uid appears,
      // remove the stale warning so a later delivery_failed state can
      // offer a legitimate new Retry.
      const confirmed = new Set(d.messages.map((m) => m.send_uid).filter((key): key is string => !!key));
      setRetryAttempts((attempts) => Object.fromEntries(
        Object.entries(attempts).filter(([key, attempt]) => attempt.state !== 'unconfirmed' || !confirmed.has(key)),
      ));
      setPending((attempts) => attempts.filter((attempt) => !confirmed.has(attempt.key)));
      // Opening is what marks it read: fetching alone never does. Once per screen visit.
      if (d.conversation.unread && !readSent.current) {
        readSent.current = true;
        api.markRead(scope, uid).catch(() => { readSent.current = false; });
      }
      return true;
    } catch (e) {
      if (version === threadGeneration.current) {
        setError(e instanceof Error ? e.message : 'Could not load this conversation.');
      }
      return false;
    }
  }, [api, scope, uid]);

  /**
   * Live update (realtime event, reconnect, app resume): re-read the latest window WITHOUT discarding older pages the
   * user already loaded, never overwriting a newer full load, and never flashing a spinner. Messages are de-duplicated
   * by id when rendered, so an event racing a send's own refresh cannot create a second bubble.
   */
  const refreshLive = useCallback(async (reconcileOlder = false) => {
    // Before the first load has applied there is nothing to refresh: that load will deliver the data itself.
    if (!scope) return;
    if (dataRef.current === null) { liveWanted.current = true; return; }
    const version = threadGeneration.current;
    const mine = ++liveSeq.current;
    try {
      const d = await api.conversation(scope, uid);
      if (version !== threadGeneration.current || mine !== liveSeq.current) return;
      // Scroll intent is NOT touched here: a background update keeps the user where they are (see onScroll).
      // The server only returns the latest window. Messages that just slid out of it stay on screen: they are folded
      // into the loaded history, so a growing thread never opens a gap. `hasMore` is left as it was: the slid-out
      // messages are contiguous with the old window, so what lies before the loaded history is unchanged.
      const prev = dataRef.current?.messages ?? [];
      const firstNew = d.messages[0]?.id ?? Number.POSITIVE_INFINITY;
      const newIds = new Set(d.messages.map((m) => m.id));
      // A window that shares nothing with what we hold (e.g. many messages arrived while offline) may hide a gap we cannot
      // see. Rather than splice across it, restart from the fresh window: "Load earlier" then pages back through the gap
      // contiguously. (When the server says there is nothing older, the window IS the whole thread: no gap is possible.)
      const gap = d.has_more && prev.length > 0 && !prev.some((m) => newIds.has(m.id));
      if (gap) {
        // Invalidate any "Load earlier" already in flight: it was computed against the history being discarded.
        threadGeneration.current++;
        earlierInFlight.current = false;
        setLoadingEarlier(false);
        setOlder([]);
        setHasMore(true);
      } else {
        const slid = prev.filter((m) => m.id < firstNew);
        if (slid.length > 0) setOlder((o) => [...o, ...slid]);
      }
      setData(d);
      dataRef.current = d;
      const confirmed = new Set(d.messages.map((m) => m.send_uid).filter((key): key is string => !!key));
      setPending((attempts) => attempts.filter((attempt) => !confirmed.has(attempt.key)));
      setRetryAttempts((attempts) => Object.fromEntries(
        Object.entries(attempts).filter(([key, attempt]) => attempt.state !== 'unconfirmed' || !confirmed.has(key)),
      ));
      // A delivery receipt can concern a message OUTSIDE the latest window (an older page the user loaded): the window above
      // cannot tell us. Re-read the pages between the window and the oldest loaded message and refresh those bubbles in place.
      const loaded = olderRef.current;
      if (reconcileOlder && loaded.length > 0 && d.messages.length > 0) {
        const seq = ++olderSeq.current;
        const oldestId = loaded[0].id;
        const fresh = new Map<number, Message>();
        try {
          let before = d.messages[0].id;
          for (let i = 0; i < 10; i++) {
            const page = await api.conversation(scope, uid, before);
            page.messages.forEach((m) => fresh.set(m.id, m));
            const first = page.messages[0]?.id;
            if (!first || first <= oldestId || !page.has_more) break;
            before = first;
          }
          if (version === threadGeneration.current && seq === olderSeq.current) {
            setOlder((o) => o.map((m) => fresh.get(m.id) ?? m));
          }
        } catch { /* keep what is shown; the next event or pull-to-refresh retries */ }
      }
      // Marked read only when the user can actually see it: a hidden (covered) screen never clears unread.
      if (d.conversation.unread && focused.current) api.markRead(scope, uid).catch(() => undefined);
    } catch { /* a failed live refresh is silent; the next event or pull-to-refresh retries */ }
  }, [api, scope, uid]);

  refreshLiveRef.current = () => refreshLive();

  useRealtime((e) => {
    if (e.kind === 'resync' || (e.kind === 'change' && e.conversation_uid === uid)) {
      void refreshLive(e.kind === 'resync' || e.type === 'status' || e.type === 'outbound');
    }
  });

  // Focus, not mount: when another screen is pushed on top, this conversation is no longer "being viewed", so it neither
  // marks new messages read nor suppresses their notification banner. Coming back refreshes (and marks read) once.
  useEffect(() => {
    const nav: any = navigation;
    const apply = (isFocused: boolean) => {
      focused.current = isFocused;
      setActiveConversation(isFocused ? uid : null);
    };
    apply(typeof nav.isFocused === 'function' ? nav.isFocused() : true);
    const offFocus = typeof nav.addListener === 'function' ? nav.addListener('focus', () => { apply(true); void refreshLive(); }) : null;
    const offBlur = typeof nav.addListener === 'function' ? nav.addListener('blur', () => apply(false)) : null;
    return () => { offFocus?.(); offBlur?.(); setActiveConversation(null); };
  }, [navigation, uid, refreshLive]);

  useEffect(() => { void load(); }, [load]);

  const loadEarlier = useCallback(async () => {
    if (!scope || !data || !hasMore || earlierInFlight.current) return;
    const first = (older[0] ?? data.messages[0])?.id;
    if (!first) return;
    const version = threadGeneration.current;
    earlierInFlight.current = true;
    setLoadingEarlier(true);
    try {
      const d = await api.conversation(scope, uid, first);
      if (version !== threadGeneration.current) return;
      stick.current = false;
      setOlder((o) => {
        const seen = new Set([...o, ...data.messages].map((m) => m.id));
        return [...d.messages.filter((m) => !seen.has(m.id)), ...o];
      });
      setHasMore(d.has_more);
    } catch { /* keep what we have; the button stays for another try */ }
    finally {
      if (version === threadGeneration.current) {
        earlierInFlight.current = false;
        setLoadingEarlier(false);
      }
    }
  }, [api, scope, uid, data, older, hasMore]);

  const dispatch = useCallback(async (key: string, body: string, mode: Pending['mode']) => {
    if (!scope || inflight.current.has(key)) return; // a second tap on the same message is a no-op
    inflight.current.add(key);
    if (mode === 'retry') {
      setRetryAttempts((p) => ({ ...p, [key]: { state: 'sending' } }));
    }
    setPending((p) => p.map((x) => (x.key === key ? { ...x, state: 'sending', error: undefined } : x)));
    try {
      if (mode === 'retry') await api.retry(scope, uid, key);
      else await api.reply(scope, uid, body, key);
      stick.current = true;
      const refreshed = await load();
      if (refreshed === 'superseded') {
        // A newer live refresh owns the view (and clears a confirmed pending bubble itself). This is not a failure: never
        // label a retry "unconfirmed" because of it — just end its "Retrying…" state; the bubble shows what the server says.
        if (mode === 'retry') setRetryAttempts((p) => { const next = { ...p }; delete next[key]; return next; });
        else setPending((p) => p.map((x) => (x.key === key ? { ...x, state: 'failed', error: 'Message accepted. Waiting for the conversation to refresh…', canRetry: false } : x)));
        return;
      }
      if (!refreshed) {
        // The send/retry itself already succeeded. Do NOT remove the bubble,
        // and do NOT offer a second send merely because the GET failed.
        const note = 'Message accepted, but history could not refresh. Refresh the conversation to confirm its status.';
        setPending((p) => p.map((x) => x.key === key ? {
          ...x, state: 'failed', error: note, canRetry: false,
        } : x));
        if (mode === 'retry') {
          setRetryAttempts((p) => ({ ...p, [key]: { state: 'unconfirmed', error: note } }));
        }
        return;
      }
      setPending((p) => p.filter((x) => x.key !== key));
      setRetryAttempts((p) => {
        const next = { ...p };
        delete next[key];
        return next;
      });
    } catch (e) {
      const err = e instanceof ApiError ? e : null;
      // Network error: the server may or may not have it — resending the SAME key is safe either way.
      const network = err?.status === 0;
      const failure = network ? 'No connection. Your message was not confirmed.' : (err?.message ?? 'Could not send.');
      if (mode === 'retry') {
        setRetryAttempts((p) => ({ ...p, [key]: { state: 'failed', error: failure } }));
      }
      setPending((p) => p.map((x) => (x.key === key ? {
        ...x,
        state: 'failed',
        error: failure,
        mode: err?.retryable ? 'retry' : 'reply',
        canRetry: network || err?.retryable === true,
      } : x)));
    } finally {
      inflight.current.delete(key);
    }
  }, [api, scope, uid, load]);

  const send = useCallback(() => {
    const body = text.trim();
    if (!body) return;
    const key = uuidv4();
    stick.current = true;
    setText('');
    setPending((p) => [...p, { key, body, state: 'sending', mode: 'reply', canRetry: false }]);
    void dispatch(key, body, 'reply');
  }, [text, dispatch]);

  const rows = useMemo<Row[]>(() => {
    // De-duplicated by id: a live refresh and an earlier page can overlap.
    const byId = new Map<number, Message>();
    for (const m of [...older, ...(data?.messages ?? [])]) byId.set(m.id, m);
    const server = [...byId.values()].sort((a, b) => a.id - b.id);
    const known = new Set(server.map((m) => m.send_uid).filter(Boolean));
    const extra: Row[] = pending.filter((p) => !known.has(p.key)).map((p) => ({ pending: true, ...p }));
    return [...server, ...extra];
  }, [older, data, pending]);

  // Day separators ("Yesterday", "Today") between messages from different days. Unsent bubbles belong to today.
  const items = useMemo<Item[]>(() => {
    const out: Item[] = [];
    let lastDay: string | null = null;
    const today = new Date().toISOString();
    for (const r of rows) {
      const at = 'pending' in r && r.pending ? today : (r as Message).created_at;
      if (lastDay === null || !sameDay(lastDay, at)) out.push({ day: true, key: `d-${at}`, label: dayLabel(at) });
      lastDay = at;
      out.push(r);
    }
    return out;
  }, [rows]);

  const title = data?.conversation?.display_name ?? data?.conversation?.phone ?? route.params.title;
  const phone = data?.conversation?.display_name ? data?.conversation?.phone : null;
  const header = (
    <View style={[s.header, { paddingTop: topInset + space.sm }]}>
      <IconButton icon="chevron-left" size={26} accessibilityLabel="Back" onPress={() => navigation.goBack()} />
      {data?.contact_uid ? (
        <Pressable accessibilityRole="button" accessibilityLabel={`${title}. Contact profile`} style={s.titleBox} hitSlop={4}
          onPress={() => navigation.navigate('ContactProfile', { uid: data.contact_uid! })}>
          <Text variant="headline" numberOfLines={1}>{title}</Text>
          <View style={s.subRow}>
            <Text size={12.5} weight="500" color={colors.textSecondary} numberOfLines={1}>{phone ?? 'Contact profile'}</Text>
            <Icon name="chevron-right" size={13} color={colors.textSecondary} />
          </View>
        </Pressable>
      ) : (
        <View style={s.titleBox}>
          <Text variant="headline" numberOfLines={1} accessibilityRole="header">{title}</Text>
          {phone ? <Text size={12.5} weight="500" color={colors.textSecondary} numberOfLines={1}>{phone}</Text> : null}
        </View>
      )}
      <View style={s.headerSpacer} />
    </View>
  );

  if (error && !data) return <View style={s.wrap}>{header}<ErrorState title="Conversation didn't load" message={error} onRetry={load} /></View>;
  if (!data) return <View style={s.wrap}>{header}<Loading /></View>;

  const canSend = text.trim() !== '';
  return (
    <KeyboardAvoidingView style={s.wrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
      {header}
      <FlatList
        ref={listRef}
        testID="conversation-messages"
        data={items}
        keyExtractor={(r) => ('day' in r ? r.key : 'pending' in r && r.pending ? `p-${r.key}` : `m-${(r as Message).id}`)}
        contentContainerStyle={s.thread}
        refreshing={refreshing}
        onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }}
        scrollEventThrottle={100}
        // Follow new messages only while the user is at (or near) the bottom; scrolling up to read history stops the following.
        onScroll={(e) => {
          const { contentOffset, contentSize, layoutMeasurement } = e.nativeEvent;
          stick.current = contentOffset.y + layoutMeasurement.height >= contentSize.height - 80;
        }}
        onContentSizeChange={() => { if (stick.current) listRef.current?.scrollToEnd({ animated: true }); }}
        ListHeaderComponent={hasMore
          ? <Button title={loadingEarlier ? 'Loading earlier messages…' : 'Load earlier messages'} variant="plain" compact disabled={loadingEarlier} onPress={() => { void loadEarlier(); }} />
          : undefined}
        renderItem={({ item }) => ('day' in item
          ? <View style={s.day}><Text variant="caption" color={colors.textSecondary}>{item.label}</Text></View>
          : <Bubble
              item={item}
              retryAttempt={'pending' in item && item.pending ? undefined : retryAttempts[(item as Message).send_uid ?? '']}
              onRetry={dispatch}
            />)}
      />
      <View testID="composer" style={[s.composer, { paddingBottom: space.sm + bottomInset }]}>
        <TextInput
          style={[s.input, fontFamilyFor('400')]}
          placeholder="Message"
          placeholderTextColor={colors.textSecondary}
          accessibilityLabel="Message"
          value={text}
          onChangeText={setText}
          multiline
          maxLength={1600}
        />
        <Pressable accessibilityRole="button" accessibilityLabel="Send" accessibilityState={{ disabled: !canSend }} onPress={send} disabled={!canSend}
          style={({ pressed }) => [s.send, pressed && { backgroundColor: colors.brandPressed }, !canSend && { opacity: 0.45 }]}>
          <Icon name="send" size={19} color={colors.onBrand} />
        </Pressable>
      </View>
    </KeyboardAvoidingView>
  );
}

function Bubble({ item, retryAttempt, onRetry }: {
  item: Row;
  retryAttempt?: RetryAttempt;
  onRetry: (key: string, body: string, mode: Pending['mode']) => void;
}) {
  if ('pending' in item && item.pending) {
    const failed = item.state === 'failed';
    return (
      <View style={s.group}>
        <View style={[s.bubble, s.out]}><Text color={colors.onBrand} style={s.bodyText}>{item.body}</Text></View>
        <View style={[s.metaRow, s.metaOut]}>
          {failed ? <Icon name="alert-circle" size={13} color={colors.danger} /> : null}
          <Text size={12} weight={failed ? '600' : '400'} color={failed ? colors.danger : colors.textSecondary} style={s.metaText}>
            {failed ? item.error : 'Sending…'}
          </Text>
        </View>
        {failed && item.canRetry ? <RetryLink onPress={() => onRetry(item.key, item.body, item.mode)} /> : null}
      </View>
    );
  }
  const m = item as Message;
  const out = m.direction === 'outbound';
  const label = out ? statusLabel(m) : null;
  const failed = out && isFailed(m.status);
  const time = clockTime(m.created_at);
  return (
    <View style={s.group}>
      <View style={[s.bubble, out ? s.out : s.in]}>
        <Text color={out ? colors.onBrand : colors.textPrimary} style={[s.bodyText, !m.body && s.italic]}>{m.body ?? (m.media_url ? '[media]' : '')}</Text>
      </View>
      <View style={[s.metaRow, out && s.metaOut]}>
        {failed ? <Icon name="alert-circle" size={13} color={colors.danger} /> : null}
        <Text size={12} weight={failed ? '600' : '400'} color={failed ? colors.danger : colors.textSecondary}>
          {time}{label ? `  ·  ${label}` : ''}
        </Text>
      </View>
      {failed ? (
        <>
          {m.status_message ? <Text size={12.5} color={colors.danger} style={[s.metaText, s.metaOutText]}>{m.status_message}</Text> : null}
          {(retryAttempt?.state === 'failed' || retryAttempt?.state === 'unconfirmed') && retryAttempt.error
            ? <Text size={12.5} color={colors.danger} style={[s.metaText, s.metaOutText]}>{retryAttempt.error}</Text> : null}
          {retryAttempt?.state === 'sending' ? <Text size={12} color={colors.textSecondary} style={s.metaOutText}>Retrying…</Text> : null}
          {m.retryable && m.send_uid && retryAttempt?.state !== 'sending' && retryAttempt?.state !== 'unconfirmed'
            ? <RetryLink onPress={() => onRetry(m.send_uid!, m.body ?? '', 'retry')} />
            : null}
        </>
      ) : null}
    </View>
  );
}

/** "Tap to retry" under a failed outgoing bubble (32pt high, 44pt with hit slop). */
function RetryLink({ onPress }: { onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" onPress={onPress} hitSlop={8} style={s.retry}>
      <Text size={12.5} weight="700" color={colors.brandPressed}>Tap to retry</Text>
    </Pressable>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.background },
  header: { flexDirection: 'row', alignItems: 'center', gap: space.xs, paddingHorizontal: space.md, paddingBottom: 6, backgroundColor: colors.surface, borderBottomWidth: 1, borderBottomColor: colors.border },
  titleBox: { flex: 1, minWidth: 0, alignItems: 'center', minHeight: 44, justifyContent: 'center' },
  subRow: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  headerSpacer: { width: 44 },
  thread: { paddingHorizontal: 14, paddingTop: 14, paddingBottom: 10, gap: 6 },
  day: { alignSelf: 'center', paddingVertical: 4, paddingHorizontal: 10, borderRadius: 10, backgroundColor: colors.dayChip, marginVertical: 4 },
  group: { gap: 4 },
  bubble: { maxWidth: '76%', paddingVertical: 10, paddingHorizontal: 14, borderRadius: 20 },
  in: { alignSelf: 'flex-start', backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.borderSoft, borderBottomLeftRadius: 6 },
  out: { alignSelf: 'flex-end', backgroundColor: colors.brand, borderBottomRightRadius: 6 },
  bodyText: { fontSize: 16, lineHeight: 21.6 },
  italic: { fontStyle: 'italic' },
  metaRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  metaOut: { alignSelf: 'flex-end', maxWidth: '85%' },
  metaText: { flexShrink: 1 },
  metaOutText: { alignSelf: 'flex-end', textAlign: 'right', maxWidth: '85%' },
  retry: { alignSelf: 'flex-end', minHeight: 32, justifyContent: 'center' },
  composer: { flexDirection: 'row', alignItems: 'flex-end', gap: 6, paddingTop: space.sm, paddingHorizontal: 10, backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
  input: { flex: 1, minHeight: 44, maxHeight: 120, borderRadius: 22, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.composerFill, paddingHorizontal: 16, paddingTop: 11, paddingBottom: 11, fontSize: 16, color: colors.textPrimary },
  send: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.brand, alignItems: 'center', justifyContent: 'center' },
});
