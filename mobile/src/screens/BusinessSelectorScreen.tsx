import React, { useMemo, useState } from 'react';
import { FlatList, StyleSheet, View } from 'react-native';
import type { Business } from '../api/types';
import { useAuth } from '../auth/AuthContext';
import { Avatar, BrandMark, Button, EmptyState, Icon, ListRow, SearchField, Text } from '../components';
import { colors, radius, space } from '../theme';
import { useBottomInset, useTopInset } from './ui';

/** Lists the Businesses the server says this person may open. Pick one, then Continue. */
export function BusinessSelectorScreen() {
  const { me, business: current, selectBusiness, signOut } = useAuth();
  const items = me?.businesses ?? [];
  const bottomInset = useBottomInset();
  const topInset = useTopInset();
  const [query, setQuery] = useState('');
  const [picked, setPicked] = useState<string | null>(current?.business_uid ?? (items.length === 1 ? items[0].business_uid : null));
  const [busy, setBusy] = useState(false);

  const shown = useMemo(() => {
    const q = query.trim().toLowerCase();
    return q ? items.filter((b) => `${b.name} ${b.workspace_name}`.toLowerCase().includes(q)) : items;
  }, [items, query]);
  const chosen = items.find((b) => b.business_uid === picked) ?? null;

  if (items.length === 0) {
    return (
      <View style={[s.wrap, { paddingTop: topInset, paddingBottom: space.md + bottomInset }]}>
        <EmptyState icon="briefcase" title="No businesses yet" message="No businesses are available to this account." />
        <Button title="Sign out" variant="plain" onPress={signOut} />
      </View>
    );
  }

  const confirm = async (b: Business | null) => {
    if (!b || busy) return;
    setBusy(true);
    try { await selectBusiness(b); } finally { setBusy(false); }
  };

  return (
    <View testID="business-selector" style={[s.wrap, { paddingBottom: space.md + bottomInset }]}>
      <FlatList
        data={shown}
        keyExtractor={(b) => b.business_uid}
        keyboardShouldPersistTaps="handled"
        contentContainerStyle={[s.content, { paddingTop: 72 + topInset - 44 }]}
        ListHeaderComponent={
          <View style={s.header}>
            <BrandMark size={44} />
            <Text variant="largeTitle" accessibilityRole="header">Choose a business</Text>
            <Text size={16} color={colors.textSecondary} style={s.sub}>You can switch any time in Settings.</Text>
            {items.length > 1 ? (
              <View style={s.search}><SearchField placeholder="Search businesses" value={query} onChangeText={setQuery} accessibilityLabel="Search businesses" /></View>
            ) : null}
          </View>
        }
        ListEmptyComponent={<Text color={colors.textSecondary} align="center" style={s.none}>No business matches “{query}”.</Text>}
        renderItem={({ item, index }) => {
          const selected = item.business_uid === picked;
          return (
            <View style={[s.cell, index === 0 && s.first, index === shown.length - 1 && s.last, index > 0 && s.divider]}>
              <ListRow
                minHeight={72}
                leading={<Avatar name={item.name} size={36} />}
                title={item.name}
                strong={selected}
                subtitle={item.features.conversations ? item.workspace_name : `${item.workspace_name} · Conversations not available`}
                trailing={selected ? <Icon name="check" size={20} color={colors.brand} /> : null}
                accessibilityLabel={`${item.name}, ${item.workspace_name}${selected ? ', selected' : ''}`}
                onPress={() => setPicked(item.business_uid)}
              />
            </View>
          );
        }}
        ListFooterComponent={<Text size={13.5} color={colors.textSecondary} style={s.note}>Only businesses you have access to are shown.</Text>}
      />
      <View style={s.actions}>
        <Button title="Continue" fullWidth disabled={!chosen} loading={busy} onPress={() => { void confirm(chosen); }} />
        <Button title="Sign out" variant="plain" fullWidth onPress={signOut} />
      </View>
    </View>
  );
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.background },
  content: { paddingHorizontal: space.md, paddingBottom: space.md },
  header: { gap: space.mid, marginBottom: space.md },
  sub: { marginTop: -6 },
  search: { marginTop: space.sm },
  cell: { backgroundColor: colors.surface, borderLeftWidth: 1, borderRightWidth: 1, borderColor: colors.borderSoft, overflow: 'hidden' },
  first: { borderTopWidth: 1, borderTopLeftRadius: radius.md, borderTopRightRadius: radius.md },
  last: { borderBottomWidth: 1, borderBottomLeftRadius: radius.md, borderBottomRightRadius: radius.md },
  divider: { borderTopWidth: 1, borderTopColor: colors.divider },
  none: { marginTop: space.lg },
  note: { paddingHorizontal: space.xs, marginTop: space.mid },
  actions: { paddingHorizontal: space.md, paddingTop: space.mid, gap: space.xs },
});
