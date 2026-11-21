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
  signedIn: boolean;
  me: Me | null;
  scope: Scope | null;
  business: Business | null;
  api: Api;
  signIn: (email: string, password: string) => Promise<void>;
  signOut: () => Promise<void>;
  selectBusiness: (b: Business) => Promise<void>;
  clearBusiness: () => Promise<void>;
};

const Ctx = createContext<State | null>(null);
export const useAuth = () => {
  const v = useContext(Ctx);
  if (!v) throw new Error('useAuth outside AuthProvider');
  return v;
};

export function AuthProvider({ children, apiOverride }: { children: React.ReactNode; apiOverride?: Api }) {
  const tokenRef = useRef<string | null>(null);
  const [ready, setReady] = useState(false);
  const [me, setMe] = useState<Me | null>(null);
  const [scope, setScope] = useState<Scope | null>(null);

  const wipe = useCallback(async () => {
    tokenRef.current = null;
    setMe(null);
    setScope(null);
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

  useEffect(() => {
    (async () => {
      try {
        if (!apiOverride) assertSecureOrigin(apiBaseUrl());
        const s = await store.loadSession();
        if (s) {
          tokenRef.current = s.token;
          await hydrate();
        }
      } catch {
        await wipe();
      } finally {
        setReady(true);
      }
    })();
  }, [apiOverride, hydrate, wipe]);

  const signIn = useCallback(async (email: string, password: string) => {
    const res = await api.login(email.trim(), password, {
      installation_id: await store.installationId(),
      platform: Platform.OS === 'ios' ? 'ios' : 'android',
      name: Constants.deviceName ?? undefined,
      app_version: Constants.expoConfig?.version,
    });
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

  const business = me?.businesses.find((b) => scope && b.business_uid === scope.businessUid && b.workspace_uid === scope.workspaceUid) ?? null;

  const value: State = { ready, signedIn: me !== null, me, scope, business, api, signIn, signOut, selectBusiness, clearBusiness };
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>;
}
