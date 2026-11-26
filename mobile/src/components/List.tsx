import React from 'react';
import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { colors, radius, space } from '../theme';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

/** Uppercase group label above a grouped list ("BUSINESS", "NOTIFICATIONS"). */
export function SectionHeader({ title, style }: { title: string; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[s.header, style]} accessibilityRole="header">
      <Text size={13} weight="600" color={colors.textSecondary} style={s.headerText}>{title.toUpperCase()}</Text>
    </View>
  );
}

/** White rounded card that holds rows separated by hairlines. */
export function ListGroup({ children, style, testID }: { children: React.ReactNode; style?: StyleProp<ViewStyle>; testID?: string }) {
  const items = React.Children.toArray(children).filter(Boolean);
  return (
    <View testID={testID} style={[s.group, style]}>
      {items.map((child, i) => <View key={i} style={i > 0 ? s.divider : null}>{child}</View>)}
    </View>
  );
}

export type IconTile = { name: IconName; bg?: string; fg?: string };

type RowProps = {
  title: string;
  /** Second line under the title (workspace, label above a value…). */
  subtitle?: string;
  /** Small label ABOVE the title (contact fields: "Mobile" / number). */
  overline?: string;
  /** Right-aligned secondary text ("1.4.0 (58)"). */
  value?: string;
  icon?: IconTile;
  leading?: React.ReactNode;
  trailing?: React.ReactNode;
  chevron?: boolean;
  /** Brand-coloured, semibold title for navigational actions ("Switch business"). */
  accent?: boolean;
  /** Bold title (selected business). */
  strong?: boolean;
  onPress?: () => void;
  accessibilityLabel?: string;
  testID?: string;
  minHeight?: number;
};

/** One row of a grouped list (design "cell"). Pressable when `onPress` is given. */
export function ListRow({ title, subtitle, overline, value, icon, leading, trailing, chevron, accent, strong, onPress, accessibilityLabel, testID, minHeight }: RowProps) {
  const content = (
    <>
      {icon ? (
        <View style={[s.tile, { backgroundColor: icon.bg ?? colors.neutralTile }]}>
          <Icon name={icon.name} size={16} color={icon.fg ?? colors.textInk} />
        </View>
      ) : null}
      {leading}
      <View style={s.main}>
        {overline ? <Text size={13} color={colors.textSecondary}>{overline}</Text> : null}
        <Text
          size={16}
          weight={accent ? '600' : strong ? '700' : '400'}
          color={accent ? colors.brandPressed : colors.textPrimary}
          numberOfLines={1}
        >{title}</Text>
        {subtitle ? <Text size={13.5} color={colors.textSecondary} numberOfLines={1}>{subtitle}</Text> : null}
      </View>
      {value ? <Text size={15} color={colors.textSecondary} numberOfLines={1} style={s.value}>{value}</Text> : null}
      {trailing}
      {chevron ? <Icon name="chevron-right" size={18} color={colors.textSecondary} /> : null}
    </>
  );
  const style = [s.row, minHeight ? { minHeight } : null, (subtitle || overline) && !minHeight ? { minHeight: 62 } : null];
  if (!onPress) return <View testID={testID} style={style}>{content}</View>;
  return (
    <Pressable testID={testID} accessibilityRole="button" accessibilityLabel={accessibilityLabel} onPress={onPress}
      style={({ pressed }) => [style, pressed && { backgroundColor: colors.background }]}>
      {content}
    </Pressable>
  );
}

const s = StyleSheet.create({
  header: { paddingHorizontal: space.xs },
  headerText: { letterSpacing: 0.8 },
  group: { backgroundColor: colors.surface, borderRadius: radius.md, borderWidth: 1, borderColor: colors.borderSoft, overflow: 'hidden' },
  divider: { borderTopWidth: 1, borderTopColor: colors.divider },
  row: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 52, paddingHorizontal: 14, paddingVertical: 8, backgroundColor: colors.surface },
  tile: { width: 30, height: 30, borderRadius: 8, alignItems: 'center', justifyContent: 'center' },
  main: { flex: 1, minWidth: 0, gap: 2 },
  value: { maxWidth: '55%' },
});
