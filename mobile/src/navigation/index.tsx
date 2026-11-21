import React from 'react';
import { NavigationContainer, createNavigationContainerRef } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { useAuth } from '../auth/AuthContext';
import { BusinessSelectorScreen } from '../screens/BusinessSelectorScreen';
import { CallsScreen } from '../screens/CallsScreen';
import { ContactProfileScreen } from '../screens/ContactProfileScreen';
import { ConversationDetailScreen } from '../screens/ConversationDetailScreen';
import { ConversationsScreen } from '../screens/ConversationsScreen';
import { LoginScreen } from '../screens/LoginScreen';
import { Button, ErrorState, Loading } from '../screens/ui';
import { View } from 'react-native';
import { NotificationRouter } from './NotificationRouter';
import type { RootStack } from './types';

const Stack = createNativeStackNavigator<RootStack>();
const navRef = createNavigationContainerRef<RootStack>();

function openConversation(uid: string) {
  if (navRef.isReady()) navRef.navigate('ConversationDetail', { uid, title: 'Conversation' });
}

export function Navigation() {
  const { ready, restoreError, retryRestore, discardStoredSession, signedIn, scope } = useAuth();
  if (!ready) return <Loading />;
  if (restoreError) {
    return (
      <View style={{ flex: 1 }}>
        <ErrorState message={restoreError} onRetry={() => { void retryRestore(); }} />
        <Button title="Sign in with another account" variant="plain" onPress={() => { void discardStoredSession(); }} />
      </View>
    );
  }

  return (
    <NavigationContainer ref={navRef}>
      <NotificationRouter openConversation={openConversation} />
      <Stack.Navigator>
        {!signedIn ? (
          <Stack.Screen name="Login" component={LoginScreen} options={{ headerShown: false }} />
        ) : !scope ? (
          <Stack.Screen name="BusinessSelector" component={BusinessSelectorScreen} options={{ title: 'Businesses' }} />
        ) : (
          <>
            <Stack.Screen name="Conversations" component={ConversationsScreen} />
            <Stack.Screen name="ConversationDetail" component={ConversationDetailScreen} options={({ route }) => ({ title: route.params.title })} />
            <Stack.Screen name="ContactProfile" component={ContactProfileScreen} options={{ title: 'Contact' }} />
            <Stack.Screen name="Calls" component={CallsScreen} />
          </>
        )}
      </Stack.Navigator>
    </NavigationContainer>
  );
}
