import { useEffect, useRef, useState } from 'react';
import * as Notifications from 'expo-notifications';
import { useAuth } from '../auth/AuthContext';
import { parseNotificationTarget, type NotificationTarget } from '../notifications/target';

type Props = {
  /** Open the conversation screen. Called only after the server has re-confirmed access. */
  openConversation: (conversationUid: string) => void;
  /** Told when a tap could not be honoured (no access / no longer available). */
  onRejected?: () => void;
};

/**
 * Turns a push tap into navigation, SAFELY. The payload is two opaque uids and is never trusted: before anything opens,
 * the server is asked again which Businesses this person may open; the tapped Business must be on that list, it is
 * selected explicitly, and only then is the conversation screen opened — where the conversation itself is fetched through
 * the authorized API (a 404 there just shows "not found"). A tap while signed out waits for sign-in; sign-out drops it.
 */
export function NotificationRouter({ openConversation, onRejected }: Props) {
  const { signedIn, scope, selectBusiness, refreshAccess } = useAuth();
  const [pending, setPending] = useState<NotificationTarget | null>(null);
  // The target whose access was confirmed and whose Business was selected. Tied to ONE target object, so a newer tap
  // can never inherit an older tap's validation.
  const [validated, setValidated] = useState<NotificationTarget | null>(null);
  const handled = useRef(new Set<string>());

  // 1. Capture taps (while running) and the tap that launched the app. A newer tap replaces the pending one.
  useEffect(() => {
    const accept = (resp: Notifications.NotificationResponse | null | undefined) => {
      if (!resp) return;
      const id = resp.notification.request.identifier;
      if (handled.current.has(id)) return;
      const target = parseNotificationTarget(resp.notification.request.content.data);
      if (!target) return;
      handled.current.add(id);
      setValidated(null);
      setPending(target);
    };
    const sub = typeof Notifications.addNotificationResponseReceivedListener === 'function'
      ? Notifications.addNotificationResponseReceivedListener(accept) : null;
    if (typeof Notifications.getLastNotificationResponseAsync === 'function') {
      void Notifications.getLastNotificationResponseAsync().then(accept).catch(() => undefined);
    }
    return () => { sub?.remove(); };
  }, []);

  // 2. A signed-out session never opens anything and keeps no stale target.
  useEffect(() => { if (!signedIn) { setPending(null); setValidated(null); } }, [signedIn]);

  // 3. Re-validate access with the server, then select the Business — for THIS pending target only. If another tap
  //    supersedes it mid-flight, this attempt is cancelled and its result discarded; the new target validates itself.
  const scopeRef = useRef(scope);
  scopeRef.current = scope;
  useEffect(() => {
    if (!pending || !signedIn || validated === pending) return;
    let cancelled = false;
    (async () => {
      const fresh = await refreshAccess();
      if (cancelled) return;
      const biz = fresh?.businesses.find((b) => b.business_uid === pending.businessUid && b.features.conversations);
      if (!biz) { setPending(null); onRejected?.(); return; }
      const cur = scopeRef.current;
      if (cur?.businessUid !== biz.business_uid || cur?.workspaceUid !== biz.workspace_uid) await selectBusiness(biz);
      if (cancelled) return;
      setValidated(pending);
    })();
    return () => { cancelled = true; };
  }, [pending, validated, signedIn, refreshAccess, selectBusiness, onRejected]);

  // 4. Once the validated target's Business is the active scope, open the conversation.
  useEffect(() => {
    if (pending && validated === pending && scope?.businessUid === pending.businessUid) {
      openConversation(pending.conversationUid);
      setPending(null);
      setValidated(null);
    }
  }, [pending, validated, scope, openConversation]);

  return null;
}
