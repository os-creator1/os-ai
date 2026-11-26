import React from 'react';
import { StyleSheet, Text as RNText, type TextProps, type TextStyle } from 'react-native';
import { colors, fontFamilyFor, type FontWeight, type as scale, type TypeVariant } from '../theme';

export type AppTextProps = TextProps & {
  variant?: TypeVariant;
  weight?: FontWeight;
  color?: string;
  size?: number;
  align?: TextStyle['textAlign'];
};

/** All app text goes through here so the type scale and the Geist family stay consistent. */
export function Text({ variant = 'body', weight, color = colors.textPrimary, size, align, style, ...rest }: AppTextProps) {
  const t = scale[variant];
  // A fontWeight given in `style` wins over the variant, and is mapped to the matching Geist family.
  const flat = StyleSheet.flatten(style) as TextStyle | undefined;
  const w = (weight ?? (flat?.fontWeight as FontWeight | undefined) ?? t.weight) as FontWeight;
  return (
    <RNText
      {...rest}
      style={[
        { fontSize: size ?? t.fontSize, color, letterSpacing: 'letterSpacing' in t ? t.letterSpacing : undefined, textAlign: align },
        style,
        fontFamilyFor(w),
      ]}
    />
  );
}
