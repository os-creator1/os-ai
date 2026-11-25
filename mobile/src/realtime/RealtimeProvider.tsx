import React, { createContext, useContext, useEffect, useMemo, useRef } from 'react';
import { AppState } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { channelFor, parseChange, type RealtimeEvent, type TransportFactory } from './types';

type Listener = (e: RealtimeEvent) => void;
type Ctx = { subscribe: (l: Listener) => () => void };
const RealtimeCtx = createContext<Ctx>({ subscribe: () => () => undefined });

/**
 * Keeps ONE live subscription for the signed-in user and the SELECTED Business, and fans change notices out to screens.
 *
 * - The subscription is torn down and rebuilt whenever the Business changes, and torn down on sign-out/401/lock (the
 *   provider only subscribes while a session and a listed Business exist). Events for any other Business are dropped.
 * - On every (re)connect and every return to foreground it emits `resync`, so anything missed while offline is re-read
 *   through the API — the socket is an optimisation, the API is the source of truth.
 * - If realtime is not configured server-side, or the transport throws, the app simply behaves as before (pull-to-refresh).
 */
export function RealtimeProvider({ children, createTransport }: { children: React.ReactNode; createTransport: TransportFactory }) {
  const { api, me, scope, signedIn } = useAuth();
  const listeners = useRef(new Set<Listener>());
  const emit = (e: RealtimeEvent) => listeners.current.forEach((l) => l(e));
  const emitRef = useRef(emit);
  emitRef.current = emit;

  const userUid = me?.user.uid ?? null;
  const businessUid = scope?.businessUid ?? null;

  useEffect(() => {
    if (!signedIn || !userUid || !businessUid) return;
    let cancelled = false;
    let teardown: (() => void) | null = null;

    (async () => {
      try {
        const cfg = await api.realtimeConfig();
        if (cancelled || !cfg.enabled || !cfg.key) return;
        const transport = createTransport(cfg, async (socketId, channel) => {
          const res = await api.broadcastAuth(socketId, channel);
          return res as { auth: string };
        });
        const offConnected = transport.onConnected(() => emitRef.current({ kind: 'resync' }));
        const offChannel = transport.subscribe(channelFor(businessUid, userUid), (payload) => {
          const change = parseChange(payload);
          // Wrong Business (a late event from before a switch) or malformed: ignore.
          if (change && change.business_uid === businessUid) emitRef.current({ kind: 'change', ...change });
        });
        const appSub = AppState.addEventListener('change', (s) => { if (s === 'active') emitRef.current({ kind: 'resync' }); });
        teardown = () => { offChannel(); offConnected(); appSub.remove(); transport.disconnect(); };
        if (cancelled) teardown();
      } catch {
        /* realtime unavailable: the app keeps working on pull-to-refresh */
      }
    })();

    return () => { cancelled = true; teardown?.(); };
  }, [api, signedIn, userUid, businessUid, createTransport]);

  const value = useMemo<Ctx>(() => ({
    subscribe: (l) => { listeners.current.add(l); return () => { listeners.current.delete(l); }; },
  }), []);

  return <RealtimeCtx.Provider value={value}>{children}</RealtimeCtx.Provider>;
}

/** Runs `handler` for every realtime event while the calling screen is mounted. */
export function useRealtime(handler: Listener) {
  const { subscribe } = useContext(RealtimeCtx);
  const ref = useRef(handler);
  ref.current = handler;
  useEffect(() => subscribe((e) => ref.current(e)), [subscribe]);
}
