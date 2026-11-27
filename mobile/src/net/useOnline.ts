import { useEffect, useState } from 'react';
import NetInfo from '@react-native-community/netinfo';

/**
 * False only when the device reports it has no connection. Unknown states count as online, so a slow or undetermined
 * check never shows an "offline" banner by mistake.
 */
export function useOnline(): boolean {
  const [online, setOnline] = useState(true);
  useEffect(() => {
    const unsubscribe = NetInfo.addEventListener((s) => {
      setOnline(!(s.isConnected === false || s.isInternetReachable === false));
    });
    return () => { if (typeof unsubscribe === 'function') unsubscribe(); };
  }, []);
  return online;
}
