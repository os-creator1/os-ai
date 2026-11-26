import React from 'react';
import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { colors, radius } from '../theme';
import { Button } from './Button';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

export type BannerTone = 'info' | 'warning' | 'error' | 'promo' | 'dark';

const TONE: Record<BannerTone, { bg: string; fg: string; icon: IconName }> = {
  info: { bg: colors.infoSoft, fg: colors.info, icon: 'info' },
  warning: { bg: colors.warningSoft, fg: colors.warning, icon: 'alert-circle' },
  error: { bg: colors.dangerSoft, fg: colors.danger, icon: 'alert-circle' },
  promo: { bg: colors.promoBg, fg: colors.promoText, icon: 'bell' },
  dark: { bg: colors.textPrimary, fg: colors.onBrand, icon: 'wifi-off' },
};

type Props = {
  tone?: BannerTone;
  title?: string;
  message: string;
  icon?: IconName;
  /** A filled button on the right ("Turn on"). */
  action?: { label: string; onPress: () => void };
  /** An inline "Retry" link after the message (error banners). */
  onRetry?: () => void;
  style?: StyleProp<ViewStyle>;
  testID?: string;
};

/** Info / warning / error banner, the notification-permission prompt, and the dark offline toast. */
export function Banner({ tone = 'info', title, message, icon, action, onRetry, style, testID }: Props) {
  const t = TONE[tone];
  return (
    <View testID={testID} style={[s.wrap, { backgroundColor: t.bg }, style]}>
      <Icon name={icon ?? t.icon} size={18} color={tone === 'promo' ? colors.brandPressed : t.fg} />
      <View style={s.body}>
        {title ? <Text size={14} weight="700" color={t.fg}>{title}</Text> : null}
        <Text size={14} weight={tone === 'dark' ? '600' : '400'} color={t.fg} style={s.lh} accessibilityRole="alert">
          {message}
          {onRetry ? ' · ' : ''}
        </Text>
        {onRetry ? (
          <Pressable accessibilityRole="button" onPress={onRetry} hitSlop={12} style={s.retry}>
            <Text size={14} weight="700" color={colors.brandPressed}>Retry</Text>
          </Pressable>
        ) : null}
      </View>
      {action ? <Button title={action.label} onPress={action.onPress} compact style={s.action} /> : null}
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { flexDirection: 'row', alignItems: 'flex-start', gap: 12, padding: 14, borderRadius: radius.md },
  body: { flex: 1, gap: 2 },
  lh: { lineHeight: 20 },
  retry: { alignSelf: 'flex-start', minHeight: 24 },
  action: { minHeight: 36, paddingHorizontal: 12, alignSelf: 'center' },
});
