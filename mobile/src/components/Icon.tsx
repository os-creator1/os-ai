import React from 'react';
import Feather from '@expo/vector-icons/Feather';
import { colors } from '../theme';

export type IconName = React.ComponentProps<typeof Feather>['name'];

/** Line icons (Feather — the same outline family the design's icons come from). Decorative: hidden from screen readers. */
export function Icon({ name, size = 20, color = colors.textPrimary }: { name: IconName; size?: number; color?: string }) {
  return <Feather name={name} size={size} color={color} accessibilityElementsHidden importantForAccessibility="no-hide-descendants" />;
}
