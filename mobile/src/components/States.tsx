import React, { useEffect, useRef } from 'react';
import { Animated, StyleSheet, View, type DimensionValue } from 'react-native';
import { colors, space } from '../theme';
import { Button } from './Button';
import { Icon, type IconName } from './Icon';
import { Text } from './Text';

type Tone = 'brand' | 'danger' | 'warning' | 'neutral';
const TONE: Record<Tone, { bg: string; fg: string }> = {
  brand: { bg: colors.brandSoft, fg: colors.brandPressed },
  danger: { bg: colors.dangerSoft, fg: colors.danger },
  warning: { bg: colors.warningSoft, fg: colors.warning },
  neutral: { bg: colors.neutralSoft, fg: colors.neutral },
};

type EmptyProps = {
  icon: IconName;
  title: string;
  message?: string;
  tone?: Tone;
  action?: { label: string; onPress: () => void; variant?: 'primary' | 'secondary' | 'plain'; icon?: IconName };
  testID?: string;
  /** Extra content under the message (links, status lines). */
  children?: React.ReactNode;
};

/** Centered icon circle + title + explanation + optional action (empty, no results, caught up, coming soon). */
export function EmptyState({ icon, title, message, tone = 'brand', action, testID, children }: EmptyProps) {
  const t = TONE[tone];
  return (
    <View testID={testID} style={s.empty}>
      <View style={[s.circle, { backgroundColor: t.bg }]}><Icon name={icon} size={28} color={t.fg} /></View>
      <Text size={19} weight="700" align="center">{title}</Text>
      {message ? <Text size={15} color={colors.textSecondary} align="center" style={s.msg}>{message}</Text> : null}
      {action ? (
        <Button title={action.label} onPress={action.onPress} variant={action.variant ?? 'secondary'} icon={action.icon} compact style={{ marginTop: space.sm }} />
      ) : null}
      {children}
    </View>
  );
}

/** A screen-level failure with a Retry button. `message` is shown as an alert so it is announced. */
export function ErrorState({ title = 'Something went wrong', message, onRetry, retryLabel = 'Try again' }: {
  title?: string; message: string; onRetry?: () => void; retryLabel?: string;
}) {
  return (
    <View style={s.empty}>
      <View style={[s.circle, { backgroundColor: TONE.danger.bg }]}><Icon name="alert-circle" size={28} color={TONE.danger.fg} /></View>
      <Text size={19} weight="700" align="center">{title}</Text>
      <Text size={15} color={colors.textSecondary} align="center" style={s.msg} accessibilityRole="alert">{message}</Text>
      {onRetry ? <Button title={retryLabel} icon="rotate-ccw" onPress={onRetry} compact style={{ marginTop: space.sm }} /> : null}
    </View>
  );
}

/** Pulsing placeholder block. */
export function Skeleton({ width, height, radius = 8, circle }: { width: DimensionValue; height: number; radius?: number; circle?: boolean }) {
  const pulse = useRef(new Animated.Value(1)).current;
  useEffect(() => {
    const loop = Animated.loop(Animated.sequence([
      Animated.timing(pulse, { toValue: 0.55, duration: 700, useNativeDriver: true }),
      Animated.timing(pulse, { toValue: 1, duration: 700, useNativeDriver: true }),
    ]));
    loop.start();
    return () => loop.stop();
  }, [pulse]);
  return <Animated.View style={{ width, height, borderRadius: circle ? height / 2 : radius, backgroundColor: colors.skeleton, opacity: pulse }} />;
}

const ROWS: [DimensionValue, DimensionValue][] = [['52%', '80%'], ['40%', '70%'], ['60%', '85%'], ['45%', '60%'], ['55%', '75%'], ['38%', '82%']];

/** Loading list: avatar + two lines per row, as in the "loading" design. */
export function SkeletonList({ rows = 6 }: { rows?: number }) {
  return (
    <View accessibilityLabel="Loading" accessibilityRole="progressbar" testID="skeleton-list">
      {ROWS.slice(0, rows).map(([a, b], i) => (
        <View key={i} style={s.skRow}>
          <Skeleton width={46} height={46} circle />
          <View style={s.skLines}>
            <Skeleton width={a} height={13} />
            <Skeleton width={b} height={11} />
          </View>
        </View>
      ))}
    </View>
  );
}

const s = StyleSheet.create({
  empty: { flex: 1, alignItems: 'center', justifyContent: 'center', gap: 10, paddingHorizontal: 40, paddingVertical: space.xl },
  circle: { width: 72, height: 72, borderRadius: 36, alignItems: 'center', justifyContent: 'center', marginBottom: 2 },
  msg: { lineHeight: 22 },
  skRow: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingVertical: 14, paddingHorizontal: space.md, backgroundColor: colors.surface },
  skLines: { flex: 1, gap: 9 },
});
