import { StatusBar } from 'expo-status-bar';
import { useFonts } from 'expo-font';
import React, { useEffect, useState } from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider } from './src/auth/AuthContext';
import { Navigation } from './src/navigation';
import { installNotificationHandler } from './src/notifications/handler';
import { RealtimeProvider } from './src/realtime/RealtimeProvider';
import { createPusherTransport } from './src/realtime/pusherTransport';
import { Loading } from './src/screens/ui';
import { setFontsReady } from './src/theme';
import { fontSources } from './src/theme/fonts';

installNotificationHandler();

/** Geist is bundled with the app; if it ever fails (or takes > 3 s) the app carries on with the system font. */
function useAppFonts(): boolean {
  const [loaded, error] = useFonts(fontSources);
  const [gaveUp, setGaveUp] = useState(false);
  useEffect(() => {
    const t = setTimeout(() => setGaveUp(true), 3000);
    return () => clearTimeout(t);
  }, []);
  setFontsReady(loaded && !error);
  return loaded || !!error || gaveUp;
}

export default function App() {
  const fontsSettled = useAppFonts();
  return (
    <SafeAreaProvider>
      {fontsSettled ? (
        <AuthProvider>
          <RealtimeProvider createTransport={createPusherTransport}>
            <Navigation />
          </RealtimeProvider>
          <StatusBar style="dark" />
        </AuthProvider>
      ) : <Loading />}
    </SafeAreaProvider>
  );
}
