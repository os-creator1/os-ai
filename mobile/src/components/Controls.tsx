import React from 'react';
import { Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { colors, radius, shadow, touch } from '../theme';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

/** Two-to-four option segmented control (All / Unread). Each segment is a tab with a selected state. */
export function SegmentedControl<T extends string>({ options, value, onChange, testID }: {
  options: { value: T; label: string }[];
  value: T;
  onChange: (v: T) => void;
  testID?: string;
}) {
  return (
    <View style={s.seg} accessibilityRole="tablist" testID={testID}>
      {options.map((o) => {
        const on = o.value === value;
        return (
          <Pressable key={o.value} accessibilityRole="tab" accessibilityState={{ selected: on }}
            onPress={() => onChange(o.value)} style={[s.segBtn, on && s.segOn]}>
            <Text size={14} weight="600" color={on ? colors.textPrimary : colors.textTertiary}>{o.label}</Text>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Round 44pt icon button. `filled` = white with border (header "New conversation"). */
export function IconButton({ icon, onPress, accessibilityLabel, filled, color = colors.textPrimary, size = 22, style, testID }: {
  icon: IconName; onPress: () => void; accessibilityLabel: string; filled?: boolean; color?: string; size?: number;
  style?: StyleProp<ViewStyle>; testID?: string;
}) {
  return (
    <Pressable testID={testID} accessibilityRole="button" accessibilityLabel={accessibilityLabel} onPress={onPress} hitSlop={4}
      style={({ pressed }) => [s.ib, filled && s.ibFilled, pressed && { backgroundColor: colors.fill }, style]}>
      <Icon name={icon} size={size} color={color} />
    </Pressable>
  );
}

/** Selectable chip (filters). */
export function Chip({ label, selected, onPress, icon }: { label: string; selected?: boolean; onPress?: () => void; icon?: IconName }) {
  return (
    <Pressable accessibilityRole="button" accessibilityState={{ selected: !!selected }} onPress={onPress}
      style={[s.chip, selected && s.chipOn]}>
      {icon ? <Icon name={icon} size={15} color={selected ? colors.onBrand : colors.textPrimary} /> : null}
      <Text size={14} weight="600" color={selected ? colors.onBrand : colors.textPrimary}>{label}</Text>
    </Pressable>
  );
}

const s = StyleSheet.create({
  seg: { flexDirection: 'row', padding: 3, borderRadius: 11, backgroundColor: colors.fill },
  segBtn: { flex: 1, height: 36, borderRadius: 9, alignItems: 'center', justifyContent: 'center' },
  segOn: { backgroundColor: colors.surface, ...shadow.segment },
  ib: { width: touch, height: touch, borderRadius: touch / 2, alignItems: 'center', justifyContent: 'center' },
  ibFilled: { backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.border },
  chip: { flexDirection: 'row', alignItems: 'center', gap: 6, minHeight: 36, paddingHorizontal: 14, borderRadius: 18, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.surface },
  chipOn: { backgroundColor: colors.textPrimary, borderColor: colors.textPrimary },
});

