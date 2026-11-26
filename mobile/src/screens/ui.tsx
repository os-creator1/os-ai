import React, { useContext } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, Text, View } from 'react-native';
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

export const Center = ({ children }: { children: React.ReactNode }) => <View style={s.center}>{children}</View>;
export const Loading = () => <Center><ActivityIndicator accessibilityLabel="Loading" color={colors.brand} /></Center>;
export const ErrorState = ({ message, onRetry }: { message: string; onRetry?: () => void }) => (
  <Center>
    <Text style={s.error} accessibilityRole="alert">{message}</Text>
    {onRetry ? <Button title="Try again" onPress={onRetry} /> : null}
  </Center>
);
export const Button = ({ title, onPress, disabled, variant = 'primary' }: { title: string; onPress: () => void; disabled?: boolean; variant?: 'primary' | 'plain' }) => (
  <Pressable accessibilityRole="button" onPress={onPress} disabled={disabled}
    style={[s.btn, variant === 'plain' && s.btnPlain, disabled && { opacity: 0.5 }]}>
    <Text style={[s.btnText, variant === 'plain' && { color: colors.brand }]}>{title}</Text>
  </Pressable>
);
const s = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', padding: space.lg, backgroundColor: colors.bg },
  error: { color: colors.danger, marginBottom: space.md, textAlign: 'center' },
  btn: { backgroundColor: colors.brand, paddingVertical: 12, paddingHorizontal: space.lg, borderRadius: 10, alignItems: 'center' },
  btnPlain: { backgroundColor: 'transparent' },
  btnText: { color: colors.brandText, fontWeight: '600', fontSize: 16 },
});
