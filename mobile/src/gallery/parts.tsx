import React from 'react';
import { Pressable, ScrollView, StyleSheet, Switch, View, type StyleProp, type ViewStyle } from 'react-native';
import { Icon, IconButton, Text, type IconName } from '../components';
import type { MockMessage } from '../mocks/design';
import { useBottomInset, useTopInset } from '../screens/ui';
import { colors, radius, shadow, space } from '../theme';

/** Every gallery screen gets a way back to the gallery. */
export type PreviewProps = { onBack: () => void };

/** A centered-title navigation bar (back or close on the left, optional text action or icon on the right). */
export function NavBar({ title, sub, left = 'back', onLeft, right, white, style }: {
  title?: string; sub?: string; left?: 'back' | 'close' | 'none'; onLeft: () => void;
  right?: { label: string; onPress?: () => void } | { icon: IconName; label: string; onPress?: () => void };
  white?: boolean; style?: StyleProp<ViewStyle>;
}) {
  const top = useTopInset();
  return (
    <View style={[s.nav, { paddingTop: top + space.sm }, white && s.navWhite, style]}>
      {left === 'none' ? <View style={s.side} /> : (
        <IconButton icon={left === 'back' ? 'chevron-left' : 'x'} size={left === 'back' ? 26 : 22} accessibilityLabel={left === 'back' ? 'Back' : 'Close'} onPress={onLeft} />
      )}
      <View style={s.navTitle}>
        {title ? <Text variant="headline" numberOfLines={1}>{title}</Text> : null}
        {sub ? (
          <View style={s.subRow}>
            <Text size={12.5} weight="500" color={colors.textSecondary} numberOfLines={1}>{sub}</Text>
            <Icon name="chevron-right" size={13} color={colors.textSecondary} />
          </View>
        ) : null}
      </View>
      {right ? ('icon' in right
        ? <IconButton icon={right.icon} accessibilityLabel={right.label} onPress={right.onPress ?? (() => undefined)} />
        : (
          <Pressable accessibilityRole="button" onPress={right.onPress} style={s.side} hitSlop={6}>
            <Text size={16} weight="700" color={colors.brandPressed}>{right.label}</Text>
          </Pressable>
        )) : <View style={s.side} />}
    </View>
  );
}

const FUTURE_TABS: { name: string; icon: IconName }[] = [
  { name: 'Inbox', icon: 'message-circle' }, { name: 'Calendar', icon: 'calendar' }, { name: 'Pipeline', icon: 'trello' },
  { name: 'Calls', icon: 'phone' }, { name: 'Settings', icon: 'settings' },
];

/** The five-tab bar the design plans for once Calendar and Pipeline ship. Static: tapping does nothing. */
export function FutureTabBar({ active }: { active: string }) {
  const bottom = useBottomInset();
  return (
    <View style={[s.tabs, { paddingBottom: 6 + bottom }]} accessibilityRole="tabbar">
      {FUTURE_TABS.map((t) => {
        const on = t.name === active;
        const c = on ? colors.brand : colors.textSecondary;
        return (
          <View key={t.name} style={s.tab} accessibilityRole="tab" accessibilityState={{ selected: on }}>
            <Icon name={t.icon} size={22} color={c} />
            <Text size={11} weight="600" color={c}>{t.name}</Text>
          </View>
        );
      })}
    </View>
  );
}

/** Dimmed backdrop + bottom sheet with grabber and a title row with a close button. */
export function Sheet({ title, onClose, children, underlay }: { title: string; onClose: () => void; children: React.ReactNode; underlay?: React.ReactNode }) {
  const bottom = useBottomInset();
  return (
    <View style={s.fill}>
      {underlay}
      <View style={s.dim} />
      <View style={[s.sheet, { paddingBottom: space.md + bottom }]} accessibilityViewIsModal>
        <View style={s.grab} />
        <View style={s.sheetHead}>
          <Text size={18} weight="800" style={s.flex} accessibilityRole="header">{title}</Text>
          <IconButton icon="x" size={18} filled accessibilityLabel="Close" onPress={onClose} style={s.close} />
        </View>
        {children}
      </View>
    </View>
  );
}

/** Static chat bubbles with day chips and delivery meta, for thread-based previews. */
export function MockThread({ messages, children }: { messages: MockMessage[]; children?: React.ReactNode }) {
  return (
    <ScrollView style={s.flex} contentContainerStyle={s.thread}>
      {messages.map((m) => (
        <View key={m.id} style={s.group}>
          {m.day ? <View style={s.day}><Text variant="caption" color={colors.textSecondary}>{m.day}</Text></View> : null}
          {m.photo ? <View style={[s.photo, m.dir === 'out' ? s.right : s.left, { backgroundColor: m.photo }]} accessibilityLabel="Photo" /> : null}
          {m.body ? (
            <View style={[s.bubble, m.dir === 'out' ? s.out : s.in]}>
              <Text color={m.dir === 'out' ? colors.onBrand : colors.textPrimary} style={s.lh}>{m.body}</Text>
            </View>
          ) : null}
          <Text size={12} color={colors.textSecondary} style={m.dir === 'out' ? s.right : s.left}>
            {m.time}{m.status ? `  ·  ✓✓ ${m.status}` : ''}
          </Text>
        </View>
      ))}
      {children}
    </ScrollView>
  );
}

/** Thread composer look-alike (attach + field + send). */
export function MockComposer({ placeholder = 'Message' }: { placeholder?: string }) {
  const bottom = useBottomInset();
  return (
    <View style={[s.composer, { paddingBottom: space.sm + bottom }]}>
      <IconButton icon="plus" accessibilityLabel="Attach" onPress={() => undefined} />
      <View style={s.box}><Text color={colors.textSecondary}>{placeholder}</Text></View>
      <View style={[s.send, { opacity: 0.45 }]}><Icon name="send" size={19} color={colors.onBrand} /></View>
    </View>
  );
}

/** Settings-style row with a switch. */
export function ToggleRow({ title, sub, value, onChange }: { title: string; sub?: string; value: boolean; onChange: (v: boolean) => void }) {
  return (
    <View style={s.toggle}>
      <View style={s.flex}>
        <Text size={16}>{title}</Text>
        {sub ? <Text size={13} color={colors.textSecondary}>{sub}</Text> : null}
      </View>
      <Switch accessibilityLabel={title} value={value} onValueChange={onChange}
        trackColor={{ true: colors.brand, false: colors.borderStrong }} thumbColor={colors.surface} ios_backgroundColor={colors.borderStrong} />
    </View>
  );
}

/** Read-only form field look (label + bordered box with icon/value or placeholder). */
export function FieldBox({ label, value, placeholder, icon, tall, style }: { label?: string; value?: string; placeholder?: string; icon?: IconName; tall?: boolean; style?: StyleProp<ViewStyle> }) {
  return (
    <View style={[s.field, style]}>
      {label ? <Text size={13.5} weight="600" color={colors.textInk}>{label}</Text> : null}
      <View style={[s.input, tall && s.tall]}>
        {icon ? <Icon name={icon} size={17} color={colors.textSecondary} /> : null}
        <Text size={16} color={value ? colors.textPrimary : colors.placeholder} numberOfLines={tall ? 3 : 1}>{value ?? placeholder}</Text>
      </View>
    </View>
  );
}

export const gs = StyleSheet.create({
  screen: { flex: 1, backgroundColor: colors.background },
  white: { flex: 1, backgroundColor: colors.surface },
  pad: { paddingHorizontal: space.md, paddingTop: space.sm, paddingBottom: space.lg, gap: space.md },
  row: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  wrap: { flexDirection: 'row', flexWrap: 'wrap', gap: space.sm },
  flex: { flex: 1, minWidth: 0 },
  card: { backgroundColor: colors.surface, borderRadius: 16, borderWidth: 1, borderColor: colors.borderSoft, padding: 14, gap: space.sm },
});

const s = StyleSheet.create({
  fill: { flex: 1 },
  flex: { flex: 1, minWidth: 0 },
  nav: { flexDirection: 'row', alignItems: 'center', gap: space.xs, paddingHorizontal: space.md, paddingBottom: 6, backgroundColor: colors.background },
  navWhite: { backgroundColor: colors.surface, borderBottomWidth: 1, borderBottomColor: colors.border },
  navTitle: { flex: 1, minWidth: 0, alignItems: 'center', minHeight: 44, justifyContent: 'center' },
  subRow: { flexDirection: 'row', alignItems: 'center', gap: 3 },
  side: { minWidth: 44, minHeight: 44, alignItems: 'center', justifyContent: 'center' },
  tabs: { flexDirection: 'row', backgroundColor: 'rgba(255,255,255,0.96)', borderTopWidth: 1, borderTopColor: colors.border, paddingTop: 6, paddingHorizontal: 4 },
  tab: { flex: 1, alignItems: 'center', gap: 3, minHeight: 50, paddingTop: 4 },
  dim: { position: 'absolute', top: 0, right: 0, bottom: 0, left: 0, backgroundColor: colors.scrim },
  sheet: { position: 'absolute', left: 0, right: 0, bottom: 0, backgroundColor: colors.surface, borderTopLeftRadius: radius.lg, borderTopRightRadius: radius.lg, paddingTop: space.sm, paddingHorizontal: space.md, gap: space.mid, ...shadow.sheet },
  grab: { width: 38, height: 5, borderRadius: 3, backgroundColor: colors.grabber, alignSelf: 'center', marginBottom: 4 },
  sheetHead: { flexDirection: 'row', alignItems: 'center', gap: space.sm },
  close: { width: 36, height: 36 },
  thread: { padding: 14, gap: 6, justifyContent: 'flex-end', flexGrow: 1 },
  group: { gap: 4 },
  day: { alignSelf: 'center', paddingVertical: 4, paddingHorizontal: 10, borderRadius: 10, backgroundColor: colors.dayChip, marginVertical: 4 },
  bubble: { maxWidth: '76%', paddingVertical: 10, paddingHorizontal: 14, borderRadius: 20 },
  in: { alignSelf: 'flex-start', backgroundColor: colors.surface, borderWidth: 1, borderColor: colors.borderSoft, borderBottomLeftRadius: 6 },
  out: { alignSelf: 'flex-end', backgroundColor: colors.brand, borderBottomRightRadius: 6 },
  lh: { lineHeight: 21.6 },
  photo: { width: 200, height: 150, borderRadius: 18 },
  left: { alignSelf: 'flex-start' },
  right: { alignSelf: 'flex-end' },
  composer: { flexDirection: 'row', alignItems: 'flex-end', gap: 6, paddingTop: space.sm, paddingHorizontal: 10, backgroundColor: colors.surface, borderTopWidth: 1, borderTopColor: colors.border },
  box: { flex: 1, minHeight: 44, borderRadius: 22, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.composerFill, paddingHorizontal: 16, justifyContent: 'center' },
  send: { width: 44, height: 44, borderRadius: 22, backgroundColor: colors.brand, alignItems: 'center', justifyContent: 'center' },
  toggle: { flexDirection: 'row', alignItems: 'center', gap: 12, minHeight: 52, paddingHorizontal: 14, paddingVertical: 8, backgroundColor: colors.surface },
  field: { gap: 6 },
  input: { minHeight: 50, borderRadius: radius.field, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.surface, paddingHorizontal: 14, flexDirection: 'row', alignItems: 'center', gap: 10 },
  tall: { minHeight: 76, alignItems: 'flex-start', paddingTop: 14 },
});
