import type { NavigatorScreenParams } from '@react-navigation/native';

/** Bottom tabs shown once a Business is selected. */
export type MainTabs = {
  Conversations: undefined;
  Calls: undefined;
  Settings: undefined;
};

export type RootStack = {
  Login: undefined;
  BusinessSelector: undefined;
  Main: NavigatorScreenParams<MainTabs> | undefined;
  ConversationDetail: { uid: string; title: string };
  ContactProfile: { uid: string };
};
