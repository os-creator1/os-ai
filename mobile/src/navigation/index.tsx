import React from 'react';
import { CommonActions, DefaultTheme, NavigationContainer, createNavigationContainerRef, type Theme } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { useAuth } from '../auth/AuthContext';
import { BusinessSelectorScreen } from '../screens/BusinessSelectorScreen';
import { CallsScreen } from '../screens/CallsScreen';
import { ContactProfileScreen } from '../screens/ContactProfileScreen';
import { ConversationDetailScreen } from '../screens/ConversationDetailScreen';
import { ConversationsScreen } from '../screens/ConversationsScreen';
import { LoginScreen } from '../screens/LoginScreen';
import { SessionRestoreErrorScreen } from '../screens/SessionRestoreErrorScreen';
import { SettingsScreen } from '../screens/SettingsScreen';
import { Loading } from '../screens/ui';
import { colors } from '../theme';
import { NotificationRouter } from './NotificationRouter';
import { TabBar } from './TabBar';
import type { MainTabs, RootStack } from './types';
import { UnreadProvider } from './unread';

const Stack = createNativeStackNavigator<RootStack>();
const Tabs = createBottomTabNavigator<MainTabs>();
const navRef = createNavigationContainerRef<RootStack>();

const theme: Theme = {
  ...DefaultTheme,
  colors: { ...DefaultTheme.colors, primary: colors.brand, background: colors.background, card: colors.surface, text: colors.textPrimary, border: colors.border },
};

function openConversation(uid: string) {
  // Reset to [tabs on Conversations, this conversation]: a tap can switch Business, and a screen left open under the
  // previous Business must not survive beneath the new one (its API calls would now target the wrong tenant).
  if (navRef.isReady()) {
    navRef.dispatch(CommonActions.reset({
      index: 1,
      routes: [
        { name: 'Main', state: { routes: [{ name: 'Conversations' }] } },
        { name: 'ConversationDetail', params: { uid, title: 'Conversation' } },
      ],
    }));
  }
}

/** Conversations · Calls · Settings. Each tab root draws its own large-title header (as in the design). */
export function MainTabsNavigator() {
  return (
    <UnreadProvider>
      <Tabs.Navigator tabBar={(props) => <TabBar {...props} />} screenOptions={{ headerShown: false }}>
        <Tabs.Screen name="Conversations" component={ConversationsScreen} />
        <Tabs.Screen name="Calls" component={CallsScreen} />
        <Tabs.Screen name="Settings" component={SettingsScreen} />
      </Tabs.Navigator>
    </UnreadProvider>
  );
}

export function Navigation() {
  const { ready, restoreError, retryRestore, discardStoredSession, signedIn, scope } = useAuth();
  if (!ready) return <Loading />;
  if (restoreError) {
    return <SessionRestoreErrorScreen message={restoreError} onRetry={retryRestore} onSignInAgain={discardStoredSession} />;
  }

  return (
    <NavigationContainer ref={navRef} theme={theme}>
      <NotificationRouter openConversation={openConversation} />
      <Stack.Navigator screenOptions={{ headerShown: false, contentStyle: { backgroundColor: colors.background } }}>
        {!signedIn ? (
          <Stack.Screen name="Login" component={LoginScreen} />
        ) : !scope ? (
          <Stack.Screen name="BusinessSelector" component={BusinessSelectorScreen} />
        ) : (
          <>
            <Stack.Screen name="Main" component={MainTabsNavigator} />
            <Stack.Screen name="ConversationDetail" component={ConversationDetailScreen} getId={({ params }) => params.uid} />
            <Stack.Screen name="ContactProfile" component={ContactProfileScreen} />
            {__DEV__ ? (
              <>
                {/* Required lazily so release bundles never include the gallery or its fake data. */}
                <Stack.Screen name="DesignGallery" getComponent={() => require('../gallery/GalleryScreens').DesignGalleryScreen} />
                <Stack.Screen name="DesignPreview" getComponent={() => require('../gallery/GalleryScreens').DesignPreviewScreen} />
              </>
            ) : null}
          </>
        )}
      </Stack.Navigator>
    </NavigationContainer>
  );
}
