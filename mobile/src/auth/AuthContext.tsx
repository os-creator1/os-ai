import React, { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Platform } from 'react-native';
import Constants from 'expo-constants';
import { createApi, type Api } from '../api/client';
import type { Business, Me, Scope } from '../api/types';
import { apiBaseUrl, assertSecureOrigin } from '../config';
import { registerForPush } from '../notifications/registerDevice';
import * as store from './storage';

type State = {
  ready: boolean;
  restoreError: string | null;
  retryRestore: () => Promise<void>;
  discardStoredSession: () => Promise<void>;
  signedIn: boolean;
  me: Me | null;
  scope: Scope | null;
  business: Business | null;
  api: Api;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  selectBusiness: (b: Business) => Promise<void>;
  clearBusiness: () => Promise<void>;
  /** Re-asks the SERVER which Businesses this person may open right now (used before honouring a notification tap). */
  refreshAccess: () => Promise<Me | null>;
};

const Ctx = createContext<State | null>(null);
export const useAuth = () => {
  const v = useContext(Ctx);
  if (!v) throw new Error('useAuth outside AuthProvider');
  return v;
};

export function AuthProvider({ children, apiOverride }: { children: React.ReactNode; apiOverride?: Api }) {
  const tokenRef = useRef<string | null>(null);
  // Bumped on every sign-in and every wipe (sign-out, 401, lock). A request that started under an earlier session must
  // not apply its result to a later one.
  const sessionEpoch = useRef(0);
  const [ready, setReady] = useState(false);
  const [restoreError, setRestoreError] = useState<string | null>(null);
  const [me, setMe] = useState<Me | null>(null);
  const [scope, setScope] = useState<Scope | null>(null);

  const wipe = useCallback(async () => {
    sessionEpoch.current++;
    tokenRef.current = null;
    setMe(null);
    setScope(null);
    setRestoreError(null);
    await store.clearSession();
  }, []);

  const api = useMemo<Api>(() => {
    if (apiOverride) return apiOverride;
    const base = apiBaseUrl();
    return createApi({ baseUrl: base, getToken: () => tokenRef.current, onUnauthorized: () => { void wipe(); } });
  }, [apiOverride, wipe]);

  const hydrate = useCallback(async () => {
    const next = await api.me();
    setMe(next);
    // Restore the last business only if the server still lists it for this person.
    const saved = await store.loadScope();
    if (saved && next.businesses.some((b) => b.business_uid === saved.businessUid && b.workspace_uid === saved.workspaceUid)) setScope(saved);
    void registerForPush(api, Constants.expoConfig?.version).catch(() => undefined);
  }, [api]);

  const retryRestore = useCallback(async () => {
    setRestoreError(null);

    try {
      if (!apiOverride) assertSecureOrigin(apiBaseUrl());
      const saved = await store.loadSession();

      if (saved) {
        tokenRef.current = saved.token;
        await hydrate();
      } else {
        tokenRef.current = null;
        setScope(null);
        setMe(null);
      }
    } catch (error) {
      // The API's 401 handler also clears the token. Keep that behavior for
      // genuine revocations/expired credentials, including test API overrides.
      if (error instanceof Error && 'status' in error && error.status === 401) {
        await wipe();
      } else {
        // A timeout, offline startup, or server 5xx is NOT a logout. Keep
        // SecureStore untouched and show a retry state instead of Login.
        setRestoreError('Could not connect to MotionGrove. Check your connection and try again.');
      }
    } finally {
      setReady(true);
    }
  }, [apiOverride, hydrate, wipe]);

  useEffect(() => {
    void retryRestore();
  }, [retryRestore]);

  const signIn = useCallback(async (email: string, password: string) => {
    const res = await api.login(email.trim(), password, {
      installation_id: await store.installationId(),
      platform: Platform.OS === 'ios' ? 'ios' : 'android',
      name: Constants.deviceName ?? undefined,
      app_version: Constants.expoConfig?.version,
    });
    sessionEpoch.current++;
    tokenRef.current = res.token;
    await store.saveSession(res.token, res.expires_at);
    await hydrate();
  }, [api, hydrate]);

  const signOut = useCallback(async () => {
    try { await api.logout(); } catch { /* the token is dropped locally either way */ }
    await wipe();
  }, [api, wipe]);

  const selectBusiness = useCallback(async (b: Business) => {
    const next = { workspaceUid: b.workspace_uid, businessUid: b.business_uid };
    setScope(next);
    await store.saveScope(next);
  }, []);

  const clearBusiness = useCallback(async () => { setScope(null); }, []);

  const refreshAccess = useCallback(async (): Promise<Me | null> => {
    const epoch = sessionEpoch.current;
    try {
      const next = await api.me();
      // Signed out (or a different account signed in) while this was in flight: discard, never resurrect the old session.
      if (epoch !== sessionEpoch.current) return null;
      setMe(next);
      // A Business that is no longer listed must stop being the active scope immediately.
      setScope((cur) => (cur && next.businesses.some((b) => b.business_uid === cur.businessUid && b.workspace_uid === cur.workspaceUid) ? cur : null));
      return next;
    } catch {
      return null;
    }
  }, [api]);

  const business = me?.businesses.find((b) => scope && b.business_uid === scope.businessUid && b.workspace_uid === scope.workspaceUid) ?? null;

  const value: State = {
    ready, restoreError, retryRestore, discardStoredSession: wipe,
    signedIn: me !== null, me, scope, business, api, signIn, signOut, selectBusiness, clearBusiness, refreshAccess,
  };
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
