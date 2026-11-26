import React from 'react';
import { Image, StyleSheet } from 'react-native';
import { Text } from './Text';

/** 30/800 screen title used by tab roots ("Conversations", "Calls", "Settings"). */
export function LargeTitle({ children, testID }: { children: string; testID?: string }) {
  return <Text variant="largeTitle" accessibilityRole="header" testID={testID} numberOfLines={1}>{children}</Text>;
}

/** The MotionGrove mark (rounded brand tile), from assets/brand-mark.png (+@2x/@3x). */
export function BrandMark({ size = 44 }: { size?: number }) {
  return <Image source={require('../../assets/brand-mark.png')} style={[s.mark, { width: size, height: size }]} accessibilityLabel="MotionGrove" />;
}

const s = StyleSheet.create({ mark: { resizeMode: 'contain' } });
