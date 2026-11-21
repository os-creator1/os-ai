import { StatusBar } from 'expo-status-bar';
import React from 'react';
import { SafeAreaProvider } from 'react-native-safe-area-context';
import { AuthProvider } from './src/auth/AuthContext';
import { Navigation } from './src/navigation';
import { installNotificationHandler } from './src/notifications/handler';
import { RealtimeProvider } from './src/realtime/RealtimeProvider';
import { createPusherTransport } from './src/realtime/pusherTransport';

installNotificationHandler();

export default function App() {
  return (
    <SafeAreaProvider>
      <AuthProvider>
        <RealtimeProvider createTransport={createPusherTransport}>
          <Navigation />
        </RealtimeProvider>
        <StatusBar style="dark" />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
