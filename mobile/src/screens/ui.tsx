import React, { useContext } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { SafeAreaInsetsContext } from 'react-native-safe-area-context';
import { colors, space } from '../theme';

/**
 * Height of the system area at the bottom of the screen (Android navigation bar, iOS home indicator). The app draws
 * edge-to-edge, so anything pinned to the bottom must add this as padding or it sits under the system bar. Reads the
 * context directly (0 without a SafeAreaProvider) instead of useSafeAreaInsets(), which throws outside a provider.
 */
export function useBottomInset(): number {
  return useContext(SafeAreaInsetsContext)?.bottom ?? 0;
}

/** Same as useBottomInset, for the status bar / notch at the top. */
export function useTopInset(): number {
  return useContext(SafeAreaInsetsContext)?.top ?? 0;
}

export const Center = ({ children }: { children: React.ReactNode }) => <View style={s.center}>{children}</View>;
export const Loading = () => <Center><ActivityIndicator accessibilityLabel="Loading" color={colors.brand} /></Center>;

// The shared design-system components. Re-exported here so existing imports (`./ui`) keep working.
export { Button, ErrorState } from '../components';

const s = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.lg, backgroundColor: colors.background },
});
