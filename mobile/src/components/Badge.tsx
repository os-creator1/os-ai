import React from 'react';
import { StyleSheet, View } from 'react-native';
import { colors } from '../theme';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

export type PillTone = 'success' | 'warning' | 'brand' | 'info' | 'neutral' | 'danger';

const PILL: Record<PillTone, { bg: string; fg: string }> = {
  success: { bg: colors.successSoft, fg: colors.success },
  warning: { bg: colors.warningSoft, fg: colors.warning },
  brand: { bg: colors.brandSoft, fg: colors.brandPressed },
  info: { bg: colors.infoSoft, fg: colors.info },
  neutral: { bg: colors.neutralSoft, fg: colors.neutral },
  danger: { bg: colors.dangerSoft, fg: colors.danger },
};

/** Status pill ("Confirmed", "Unpaid", "Lead", "Off"…). */
export function Pill({ label, tone = 'neutral', icon }: { label: string; tone?: PillTone; icon?: IconName }) {
  const t = PILL[tone];
  return (
    <View style={[s.pill, { backgroundColor: t.bg }]}>
      {icon ? <Icon name={icon} size={12} color={t.fg} /> : null}
      <Text size={12.5} weight="700" color={t.fg} numberOfLines={1}>{label}</Text>
    </View>
  );
}

/** Unread dot (10pt, brand). */
export function UnreadDot({ label = 'Unread' }: { label?: string }) {
  return <View accessibilityLabel={label} style={s.dot} />;
}

/** Red count bubble with a white ring (tab bar). No count → a plain dot. */
export function CountBadge({ count }: { count?: number }) {
  if (count === undefined) return <View style={[s.count, s.countDot]} />;
  return (
    <View style={s.count}>
      <Text size={11} weight="700" color={colors.onBrand}>{count > 99 ? '99+' : String(count)}</Text>
    </View>
  );
}

const s = StyleSheet.create({
  pill: { flexDirection: 'row', alignItems: 'center', gap: 5, height: 26, paddingHorizontal: 10, borderRadius: 13, alignSelf: 'flex-start' },
  dot: { width: 10, height: 10, borderRadius: 5, backgroundColor: colors.unread },
  count: { minWidth: 18, height: 18, paddingHorizontal: 5, borderRadius: 9, backgroundColor: colors.brand, alignItems: 'center', justifyContent: 'center', borderWidth: 2, borderColor: colors.surface },
  countDot: { minWidth: 12, width: 12, height: 12, paddingHorizontal: 0, borderRadius: 6 },
});
