import React, { useState } from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import { BrandMark, Button, Chip, Icon, ListGroup, ListRow, SectionHeader, Text, UnreadDot } from '../components';
import * as mock from '../mocks/design';
import { useBottomInset, useTopInset } from '../screens/ui';
import { colors, space } from '../theme';
import { NavBar, ToggleRow, gs, type PreviewProps } from './parts';

const TONE = {
  brand: { bg: colors.brandSoft, fg: colors.brandPressed },
  success: { bg: colors.successSoft, fg: colors.success },
  info: { bg: colors.infoSoft, fg: colors.info },
  warning: { bg: colors.warningSoft, fg: colors.warning },
  danger: { bg: colors.dangerSoft, fg: colors.danger },
  violet: { bg: colors.violetSoft, fg: colors.violet },
};

/** Activity centre: what automations, bookings and payments did, grouped by day. */
export function ActivityCentrePreview({ onBack }: PreviewProps) {
  const [filter, setFilter] = useState('All');
  return (
    <View style={gs.white}>
      <NavBar title="Activity" onLeft={onBack} right={{ label: 'Read all' }} style={{ backgroundColor: colors.surface }} />
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={s.chipsBar} contentContainerStyle={s.chips}>
        {['All', 'Automations', 'Bookings', 'Payments'].map((f) => <Chip key={f} label={f} selected={filter === f} onPress={() => setFilter(f)} />)}
      </ScrollView>
      <ScrollView>
        {mock.activity.map((group) => (
          <View key={group.section}>
            <View style={s.dayHead}><SectionHeader title={group.section} /></View>
            {group.items.map((a) => (
              <View key={a.title} style={s.item}>
                <View style={[s.tile, { backgroundColor: TONE[a.tone].bg }]}><Icon name={a.icon} size={18} color={TONE[a.tone].fg} /></View>
                <View style={gs.flex}>
                  <Text size={15.5} weight={a.unread ? '700' : '600'}>{a.title}</Text>
                  <Text size={13.5} color={colors.textSecondary}>{a.sub}</Text>
                </View>
                <View style={s.side}>
                  <Text size={12.5} color={colors.textSecondary}>{a.time}</Text>
                  {a.unread ? <UnreadDot /> : null}
                </View>
              </View>
            ))}
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

/** Per-category push preferences. */
export function NotificationPreferencesPreview({ onBack }: PreviewProps) {
  const [values, setValues] = useState<Record<string, boolean>>(
    Object.fromEntries(mock.notificationPrefs.flatMap((g) => g.items.map((i) => [i.key, i.on]))),
  );
  return (
    <View style={gs.screen}>
      <NavBar title="Notifications" onLeft={onBack} />
      <ScrollView contentContainerStyle={[gs.pad, { gap: 20 }]}>
        {mock.notificationPrefs.map((g) => (
          <View key={g.section} style={{ gap: space.sm }}>
            <SectionHeader title={g.section} />
            <ListGroup>
              {g.items.map((i) => (
                <ToggleRow key={i.key} title={i.title} sub={i.sub} value={values[i.key]} onChange={(v) => setValues((x) => ({ ...x, [i.key]: v }))} />
              ))}
            </ListGroup>
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

/** Pre-permission explainer shown before the system notification prompt. */
export function NotificationsExplainerPreview({ onBack }: PreviewProps) {
  const top = useTopInset();
  const bottom = useBottomInset();
  return (
    <View style={[gs.screen, { paddingTop: top }]}>
      <View style={s.explainer}>
        <View style={s.notif}>
          <BrandMark size={34} />
          <View style={gs.flex}>
            <Text size={14} weight="700">{mock.thread.name}</Text>
            <Text size={13.5} color={colors.textInk} numberOfLines={1}>Perfect! Can we add the guest book too?</Text>
          </View>
          <Text size={12} color={colors.textSecondary}>now</Text>
        </View>
        <View style={[s.notif, s.notifBack]}>
          <BrandMark size={30} />
          <View style={gs.flex}>
            <Text size={13.5} weight="700">Deposit received</Text>
            <Text size={13} color={colors.textSecondary}>Jordan Lee paid $250</Text>
          </View>
          <Text size={12} color={colors.textSecondary}>now</Text>
        </View>
        <Text size={28} weight="800" align="center" style={{ marginTop: space.lg }}>Never miss a lead</Text>
        <Text size={16} color={colors.textSecondary} align="center" style={{ lineHeight: 24 }}>
          We'll ping you for new messages, bookings and payments. You choose what you get in Settings.
        </Text>
      </View>
      <View style={[s.actions, { paddingBottom: space.md + bottom }]}>
        <Button title="Turn on notifications" fullWidth onPress={onBack} />
        <Button title="Not now" variant="plain" fullWidth onPress={onBack} />
      </View>
    </View>
  );
}

/** About: version, legal links, account deletion (opens the website). */
export function AboutPreview({ onBack }: PreviewProps) {
  return (
    <View style={gs.screen}>
      <NavBar title="About" onLeft={onBack} />
      <ScrollView contentContainerStyle={[gs.pad, { gap: 20 }]}>
        <View style={s.aboutHero}>
          <BrandMark size={72} />
          <Text variant="title">MotionGrove</Text>
          <Text size={14} color={colors.textSecondary}>Version {mock.about.version}</Text>
        </View>
        <ListGroup>
          {mock.about.links.map((l) => <ListRow key={l} title={l} trailing={<Icon name="external-link" size={16} color={colors.textSecondary} />} onPress={() => undefined} />)}
        </ListGroup>
        <View style={{ gap: space.sm }}>
          <ListGroup>
            <ListRow icon={{ name: 'trash-2', bg: colors.dangerSoft, fg: colors.danger }} title="Delete account" accent
              trailing={<Icon name="external-link" size={16} color={colors.danger} />} onPress={() => undefined} />
          </ListGroup>
          <Text size={13} color={colors.textSecondary} style={{ paddingHorizontal: space.xs }}>
            Opens the MotionGrove website, where you can permanently delete your account and data.
          </Text>
        </View>
      </ScrollView>
    </View>
  );
}

const s = StyleSheet.create({
  chipsBar: { flexGrow: 0 },
  chips: { gap: space.sm, paddingHorizontal: space.md, paddingBottom: space.mid },
  dayHead: { paddingHorizontal: space.md, paddingVertical: space.sm, backgroundColor: colors.background, borderTopWidth: 1, borderTopColor: colors.divider },
  item: { flexDirection: 'row', alignItems: 'center', gap: 12, paddingHorizontal: space.md, paddingVertical: 14, borderBottomWidth: 1, borderBottomColor: colors.divider },
  tile: { width: 36, height: 36, borderRadius: 10, alignItems: 'center', justifyContent: 'center' },
  side: { alignItems: 'flex-end', gap: 6, alignSelf: 'flex-start' },
  explainer: { flex: 1, justifyContent: 'center', paddingHorizontal: space.lg, gap: space.mid },
  notif: { flexDirection: 'row', alignItems: 'center', gap: 10, padding: 12, borderRadius: 18, backgroundColor: colors.surface, shadowColor: '#000', shadowOpacity: 0.08, shadowRadius: 12, shadowOffset: { width: 0, height: 4 }, elevation: 3 },
  notifBack: { marginHorizontal: space.sm, opacity: 0.85, marginTop: -4 },
  actions: { paddingHorizontal: space.md, gap: space.xs },
  aboutHero: { alignItems: 'center', gap: 6, paddingVertical: space.md },
});

