import React, { useCallback, useEffect, useState } from 'react';
import { Linking, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import type { ContactProfile } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { Avatar, ErrorState, Icon, IconButton, ListGroup, ListRow, Pill, SectionHeader, Text, type IconName } from '../components';
import type { RootStack } from '../navigation/types';
import { colors, radius, space } from '../theme';
import { Loading, useBottomInset, useTopInset } from './ui';

type Props = NativeStackScreenProps<RootStack, 'ContactProfile'>;

export function ContactProfileScreen({ route, navigation }: Props) {
  const { api, scope } = useAuth();
  const bottomInset = useBottomInset();
  const topInset = useTopInset();
  const [c, setC] = useState<ContactProfile | null>(null);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    if (!scope) return;
    setError(null);
    try { setC(await api.contact(scope, route.params.uid)); }
    catch (e) { setError(e instanceof Error ? e.message : 'Could not load this contact.'); }
  }, [api, scope, route.params.uid]);
  useEffect(() => { void load(); }, [load]);

  const header = (
    <View style={[s.nav, { paddingTop: topInset + space.sm }]}>
      <IconButton icon="chevron-left" size={26} accessibilityLabel="Back" onPress={() => navigation.goBack()} />
    </View>
  );

  if (error) return <View style={s.wrap}>{header}<ErrorState title="Contact didn't load" message={error} onRetry={load} /></View>;
  if (!c) return <View style={s.wrap}>{header}<Loading /></View>;

  const name = c.display_name ?? c.phone;
  return (
    <View style={s.wrap}>
      {header}
      <ScrollView contentContainerStyle={[s.content, { paddingBottom: space.md + bottomInset }]}>
        <View style={s.hero}>
          <Avatar name={c.display_name} size={88} />
          <Text size={24} weight="800" align="center" accessibilityRole="header">{name}</Text>
          {!c.subscribed ? <Pill label="Opted out of messages" tone="danger" icon="slash" /> : null}
        </View>

        <View style={s.actions}>
          <Action icon="message-circle" label="Message" onPress={() => navigation.goBack()} />
          <Action icon="phone" label="Call" onPress={() => { void Linking.openURL(`tel:${c.phone}`); }} />
          {c.email ? <Action icon="mail" label="Email" onPress={() => { void Linking.openURL(`mailto:${c.email}`); }} /> : null}
        </View>

        <View style={s.section}>
          <SectionHeader title="Contact" />
          <ListGroup>
            <ListRow overline="Mobile" title={c.phone} />
            {c.email ? <ListRow overline="Email" title={c.email} /> : null}
          </ListGroup>
        </View>

        {c.fields.length > 0 ? (
          <View style={s.section}>
            <SectionHeader title="Details" />
            <ListGroup>
              {c.fields.map((f) => <ListRow key={f.tag} title={f.label} value={f.value ?? '—'} />)}
            </ListGroup>
          </View>
        ) : null}
      </ScrollView>
    </View>
  );
}

/** Square-ish action button: icon over a label (Message · Call · Email). */
function Action({ icon, label, onPress }: { icon: IconName; label: string; onPress: () => void }) {
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={label} onPress={onPress}
      style={({ pressed }) => [s.action, pressed && { backgroundColor: colors.neutralTile }]}>
      <Icon name={icon} size={20} color={colors.brandPressed} />
      <Text size={13} weight="700" color={colors.brandPressed}>{label}</Text>
    </Pressable>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.background },
  nav: { flexDirection: 'row', alignItems: 'center', paddingHorizontal: space.md, minHeight: 44 },
  content: { paddingHorizontal: space.md, paddingTop: space.sm, gap: 18 },
  hero: { alignItems: 'center', gap: space.sm },
  actions: { flexDirection: 'row', gap: 10 },
  action: { flex: 1, minHeight: 64, borderRadius: radius.button, borderWidth: 1, borderColor: colors.borderStrong, backgroundColor: colors.surface, alignItems: 'center', justifyContent: 'center', gap: 4 },
  section: { gap: space.sm },
});
