import React from 'react';
import { Pressable, StyleSheet, View } from 'react-native';
import type { BottomTabBarProps } from '@react-navigation/bottom-tabs';
import { CountBadge, Icon, Text, type IconName } from '../components';
import { useBottomInset } from '../screens/ui';
import { colors } from '../theme';
import { useUnread } from './unread';

const ICONS: Record<string, IconName> = { Conversations: 'message-circle', Calls: 'phone', Settings: 'settings' };

/**
 * The design's tab bar: icon over an 11pt label, brand colour when active, unread dot on Conversations. Drawn
 * edge-to-edge, so it pads itself by the system navigation-bar height (useBottomInset).
 */
export function TabBar({ state, navigation }: BottomTabBarProps) {
  const bottomInset = useBottomInset();
  const { hasUnread } = useUnread();
  return (
    <View testID="tab-bar" accessibilityRole="tabbar" style={[s.bar, { paddingBottom: 6 + bottomInset }]}>
      {state.routes.map((route, index) => {
        const focused = state.index === index;
        const color = focused ? colors.brand : colors.textSecondary;
        const unread = route.name === 'Conversations' && hasUnread;
        const onPress = () => {
          const event = navigation.emit({ type: 'tabPress', target: route.key, canPreventDefault: true });
          if (!focused && !event.defaultPrevented) navigation.navigate(route.name, route.params);
        };
        return (
          <Pressable
            key={route.key}
            accessibilityRole="tab"
            accessibilityLabel={unread ? `${route.name}, unread messages` : route.name}
            accessibilityState={{ selected: focused }}
            testID={`tab-${route.name}`}
            onPress={onPress}
            style={s.tab}
          >
            <View>
              <Icon name={ICONS[route.name] ?? 'circle'} size={22} color={color} />
              {unread ? <View style={s.badge} testID="tab-unread-badge"><CountBadge /></View> : null}
            </View>
            <Text size={11} weight="600" color={color}>{route.name}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

const s = StyleSheet.create({
  bar: { flexDirection: 'row', backgroundColor: 'rgba(255,255,255,0.96)', borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 6, paddingHorizontal: 4 },
  tab: { flex: 1, alignItems: 'center', gap: 3, minHeight: 50, paddingTop: 4 },
  badge: { position: 'absolute', top: -4, right: -8 },
});
