import * as Notifications from 'expo-notifications';
import { isActiveConversation, parseNotificationTarget } from './target';

/** Foreground behaviour: show the banner, except for the conversation the user is already looking at. */
export function installNotificationHandler(): void {
  if (typeof Notifications.setNotificationHandler !== 'function') return;
  Notifications.setNotificationHandler({
    handleNotification: async (n) => {
      const target = parseNotificationTarget(n.request.content.data);
      const suppress = target !== null && isActiveConversation(target.conversationUid);
      return { shouldShowBanner: !suppress, shouldShowList: !suppress, shouldPlaySound: !suppress, shouldSetBadge: false };
    },
  });
}
