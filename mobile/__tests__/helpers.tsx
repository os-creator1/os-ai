import React, { useEffect, useRef } from 'react';
import { Text } from 'react-native';
import { useAuth } from '../src/auth/AuthContext';

/** Test harness: signs in through the real provider, selects the first business, then renders children. */
export function Login({ children }: { children: React.ReactNode; api: unknown }) {
  const { signIn, selectBusiness, me, scope } = useAuth();
  const started = useRef(false);
  useEffect(() => {
    if (started.current) return;
    started.current = true;
    void signIn('pat@example.test', 'pw');
  }, [signIn]);
  useEffect(() => { if (me && !scope) void selectBusiness(me.businesses[0]); }, [me, scope, selectBusiness]);
  if (!me) return null;
  return <><Text>ready</Text>{children}</>;
}
