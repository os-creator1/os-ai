import React from 'react';
import { StyleSheet, TextInput as RNTextInput, View, type TextInputProps } from 'react-native';
import { colors, fontFamilyFor, radius, touch } from '../theme';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

type FieldProps = TextInputProps & { label?: string; icon?: IconName; error?: string | null };

/** Labelled form input (design "Input"): 50pt, 12 radius, strong border; red border + message on error. */
export function TextField({ label, icon, error, style, ...rest }: FieldProps) {
  return (
    <View style={s.field}>
      {label ? <Text size={13.5} weight="600" color={colors.textInk}>{label}</Text> : null}
      <View style={[s.input, error ? { borderColor: colors.danger } : null]}>
        {icon ? <Icon name={icon} size={18} color={colors.textSecondary} /> : null}
        <RNTextInput
          accessibilityLabel={rest.accessibilityLabel ?? label}
          placeholderTextColor={colors.placeholder}
          {...rest}
          style={[s.text, fontFamilyFor('400'), style]}
        />
      </View>
      {error ? <Text variant="caption" color={colors.danger} accessibilityRole="alert">{error}</Text> : null}
    </View>
  );
}

/** Filled search field (design "search"): 44pt, 12 radius, magnifier icon. */
export function SearchField({ style, ...rest }: TextInputProps) {
  return (
    <View style={s.search}>
      <Icon name="search" size={17} color={colors.textSecondary} />
      <RNTextInput
        accessibilityLabel="Search"
        placeholderTextColor={colors.textSecondary}
        autoCapitalize="none"
        autoCorrect={false}
        returnKeyType="search"
        clearButtonMode="while-editing"
        {...rest}
        style={[s.text, fontFamilyFor('400'), style]}
      />
    </View>
  );
}

const s = StyleSheet.create({
  field: { gap: 6 },
  input: { minHeight: 50, borderRadius: radius.field, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.surface, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 10 },
  text: { flex: 1, fontSize: 16, color: colors.textPrimary, paddingVertical: 10 },
  search: { minHeight: touch, borderRadius: radius.field, backgroundColor: colors.fill, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 8 },
});
