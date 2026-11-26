import React from 'react';
import { ActivityIndicator, Pressable, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';
import { colors, radius, touch } from '../theme';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

export type ButtonVariant = 'primary' | 'secondary' | 'plain' | 'destructive';

type Props = {
  title: string;
  onPress: () => void;
  variant?: ButtonVariant;
  disabled?: boolean;
  /** Shows a spinner and blocks presses (e.g. "Sending"). */
  loading?: boolean;
  icon?: IconName;
  /** 44pt instead of 50pt high (inline actions inside banners / empty states). */
  compact?: boolean;
  fullWidth?: boolean;
  accessibilityLabel?: string;
  testID?: string;
  style?: StyleProp<ViewStyle>;
};

const tone: Record<ButtonVariant, { bg: string; pressed: string; fg: string; border?: string }> = {
  primary: { bg: colors.brand, pressed: colors.brandPressed, fg: colors.onBrand },
  secondary: { bg: colors.surface, pressed: colors.neutralTile, fg: colors.textPrimary, border: colors.borderStrong },
  plain: { bg: 'transparent', pressed: colors.brandSoft, fg: colors.brandPressed },
  destructive: { bg: colors.dangerSoft, pressed: colors.brandSoft, fg: colors.danger },
};

export function Button({ title, onPress, variant = 'primary', disabled, loading, icon, compact, fullWidth, accessibilityLabel, testID, style }: Props) {
  const t = tone[variant];
  const inactive = !!disabled || !!loading;
  return (
    <Pressable
      testID={testID}
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: inactive, busy: !!loading }}
      onPress={onPress}
      disabled={inactive}
      style={({ pressed }) => [
        s.base,
        compact && s.compact,
        fullWidth && s.full,
        { backgroundColor: pressed ? t.pressed : t.bg },
        t.border ? { borderWidth: 1, borderColor: t.border } : null,
        disabled && !loading && s.disabled,
        style,
      ]}
    >
      <View style={s.row}>
        {loading
          ? <ActivityIndicator size="small" color={t.fg} />
          : icon ? <Icon name={icon} size={18} color={t.fg} /> : null}
        <Text weight="700" color={t.fg} size={compact ? 15 : 16}>{title}</Text>
      </View>
    </Pressable>
  );
}

const s = StyleSheet.create({
  base: { minHeight: 50, paddingHorizontal: 20, borderRadius: radius.button, alignItems: 'center', justifyContent: 'center', alignSelf: 'center' },
  compact: { minHeight: touch, paddingHorizontal: 16, borderRadius: radius.sm },
  full: { alignSelf: 'stretch' },
  disabled: { opacity: 0.45 },
  row: { flexDirection: 'row', alignItems: 'center', gap: 8 },
});
