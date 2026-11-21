import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { FlatList, KeyboardAvoidingView, Platform, Pressable, StyleSheet, Text, TextInput, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { ApiError } from '../api/client';
import type { ConversationDetail, Message } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { isFailed, statusLabel } from '../conversations/status';
import { uuidv4 } from '../conversations/uuid';
import { setActiveConversation } from '../notifications/target';
import { useRealtime } from '../realtime/RealtimeProvider';
import type { RootStack } from '../navigation/types';
import { colors, space } from '../theme';
import { Button, ErrorState, Loading } from './ui';

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

export function ConversationDetailScreen({ route, navigation }: Props) {
  const { api, scope } = useAuth();
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
  const listRef = useRef<FlatList<Row>>(null);
  const stick = useRef(true);
  const readSent = useRef(false);
  const earlierInFlight = useRef(false);
  const [loadingEarlier, setLoadingEarlier] = useState(false);
  const threadGeneration = useRef(0);

  const load = useCallback(async (): Promise<boolean> => {
    if (!scope) return false;
    const version = ++threadGeneration.current;
    earlierInFlight.current = false;
    setLoadingEarlier(false);
    setError(null);
    try {
      const d = await api.conversation(scope, uid);
      if (version !== threadGeneration.current) return false;
      setData(d);
      setHasMore(d.has_more);
      setOlder([]);
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
  const refreshLive = useCallback(async () => {
    if (!scope) return;
    const version = threadGeneration.current;
    try {
      const d = await api.conversation(scope, uid);
      if (version !== threadGeneration.current) return;
      stick.current = true;
      setData(d);
      const confirmed = new Set(d.messages.map((m) => m.send_uid).filter((key): key is string => !!key));
      setPending((attempts) => attempts.filter((attempt) => !confirmed.has(attempt.key)));
      setRetryAttempts((attempts) => Object.fromEntries(
        Object.entries(attempts).filter(([key, attempt]) => attempt.state !== 'unconfirmed' || !confirmed.has(key)),
      ));
      // A message that arrives while the conversation is open is read the moment it is seen.
      if (d.conversation.unread) api.markRead(scope, uid).catch(() => undefined);
    } catch { /* a failed live refresh is silent; the next event or pull-to-refresh retries */ }
  }, [api, scope, uid]);

  useRealtime((e) => {
    if (e.kind === 'resync' || (e.kind === 'change' && e.conversation_uid === uid)) void refreshLive();
  });

  useEffect(() => {
    setActiveConversation(uid);
    return () => setActiveConversation(null);
  }, [uid]);

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

  if (error && !data) return <ErrorState message={error} onRetry={load} />;
  if (!data) return <Loading />;

  return (
    <KeyboardAvoidingView style={s.wrap} behavior={Platform.OS === 'ios' ? 'padding' : undefined} keyboardVerticalOffset={Platform.OS === 'ios' ? 90 : 0}>
      {data.contact_uid ? (
        <Button title="Contact profile" variant="plain" onPress={() => navigation.navigate('ContactProfile', { uid: data.contact_uid! })} />
      ) : null}
      <FlatList
        ref={listRef}
        testID="conversation-messages"
        data={rows}
        keyExtractor={(r) => ('pending' in r && r.pending ? `p-${r.key}` : `m-${(r as Message).id}`)}
        contentContainerStyle={{ padding: space.md }}
        refreshing={refreshing}
        onRefresh={async () => { setRefreshing(true); await load(); setRefreshing(false); }}
        onContentSizeChange={() => { if (stick.current) listRef.current?.scrollToEnd({ animated: true }); }}
        ListHeaderComponent={hasMore
          ? <Button title={loadingEarlier ? 'Loading earlier messages…' : 'Load earlier messages'} variant="plain" disabled={loadingEarlier} onPress={() => { void loadEarlier(); }} />
          : undefined}
        renderItem={({ item }) => <Bubble
          item={item}
          retryAttempt={'pending' in item && item.pending ? undefined : retryAttempts[(item as Message).send_uid ?? '']}
          onRetry={dispatch}
        />}
      />
      <View style={s.composer}>
        <TextInput
          style={s.input}
          placeholder="Message"
          accessibilityLabel="Message"
          value={text}
          onChangeText={setText}
          multiline
          maxLength={1600}
        />
        <Pressable accessibilityRole="button" accessibilityLabel="Send" onPress={send} disabled={text.trim() === ''}
          style={[s.send, text.trim() === '' && { opacity: 0.4 }]}>
          <Text style={s.sendText}>Send</Text>
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
      <View style={[s.bubble, s.out]}>
        <Text style={s.outText}>{item.body}</Text>
        <Text style={s.meta}>{failed ? item.error : 'Sending…'}</Text>
        {failed && item.canRetry ? <Pressable accessibilityRole="button" onPress={() => onRetry(item.key, item.body, item.mode)}><Text style={s.retry}>Retry</Text></Pressable> : null}
      </View>
    );
  }
  const m = item as Message;
  const out = m.direction === 'outbound';
  const label = out ? statusLabel(m) : null;
  return (
    <View style={[s.bubble, out ? s.out : s.in]}>
      <Text style={out ? s.outText : s.inText}>{m.body ?? (m.media_url ? '[media]' : '')}</Text>
      <Text style={s.meta}>{new Date(m.created_at).toLocaleString()}{label ? `  ·  ${label}` : ''}</Text>
      {out && isFailed(m.status) ? (
        <>
          {m.status_message ? <Text style={s.errorText}>{m.status_message}</Text> : null}
          {(retryAttempt?.state === 'failed' || retryAttempt?.state === 'unconfirmed') && retryAttempt.error ? <Text style={s.errorText}>{retryAttempt.error}</Text> : null}
          {retryAttempt?.state === 'sending' ? <Text style={s.meta}>Retrying…</Text> : null}
          {m.retryable && m.send_uid && retryAttempt?.state !== 'sending' && retryAttempt?.state !== 'unconfirmed'
            ? <Pressable accessibilityRole="button" onPress={() => onRetry(m.send_uid!, m.body ?? '', 'retry')}><Text style={s.retry}>Retry</Text></Pressable>
            : null}
        </>
      ) : null}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.bg },
  bubble: { maxWidth: '82%', padding: 10, borderRadius: 14, marginBottom: space.sm },
  in: { alignSelf: 'flex-start', backgroundColor: colors.card, borderColor: colors.line, borderWidth: 1 },
  out: { alignSelf: 'flex-end', backgroundColor: colors.brand },
  inText: { color: colors.text }, outText: { color: colors.brandText },
  meta: { fontSize: 11, color: colors.muted, marginTop: 4 },
  errorText: { color: colors.danger, marginTop: 4 },
  retry: { color: colors.brandText, fontWeight: '700', marginTop: 6, textDecorationLine: 'underline' },
  composer: { flexDirection: 'row', alignItems: 'flex-end', padding: space.sm, gap: space.sm, borderTopColor: colors.line, borderTopWidth: StyleSheet.hairlineWidth, backgroundColor: colors.card },
  input: { flex: 1, maxHeight: 120, borderColor: colors.line, borderWidth: 1, borderRadius: 18, paddingHorizontal: 14, paddingVertical: 8, fontSize: 16 },
  send: { backgroundColor: colors.brand, borderRadius: 18, paddingHorizontal: 16, paddingVertical: 10 },
  sendText: { color: colors.brandText, fontWeight: '700' },
});
