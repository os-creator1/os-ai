import React from 'react';
import { FlatList, Pressable, StyleSheet, Text, View } from 'react-native';
import { useAuth } from '../auth/AuthContext';
import { colors, space } from '../theme';
import { Button, Center, useBottomInset } from './ui';

export function BusinessSelectorScreen() {
  const { me, selectBusiness, signOut } = useAuth();
  const items = me?.businesses ?? [];
  const bottomInset = useBottomInset();

  if (items.length === 0) {
    return (
      <Center>
        <Text style={{ marginBottom: space.md, textAlign: 'center' }}>No businesses are available to this account.</Text>
        <Button title="Sign out" onPress={signOut} variant="plain" />
      </Center>
    );
  }
  return (
    <View testID="business-selector" style={[s.wrap, { paddingBottom: space.md + bottomInset }]}>
      <Text style={s.hello}>Choose a business</Text>
      <FlatList
        data={items}
        keyExtractor={(b) => b.business_uid}
        renderItem={({ item }) => (
          <Pressable accessibilityRole="button" style={s.row} onPress={() => void selectBusiness(item)}>
            <Text style={s.name}>{item.name}</Text>
            <Text style={s.sub}>{item.workspace_name}</Text>
            {!item.features.conversations ? <Text style={s.sub}>Conversations not available</Text> : null}
          </Pressable>
        )}
      />
      <Button title="Sign out" onPress={signOut} variant="plain" />
    </View>
  );
}
const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.bg, padding: space.md },
  hello: { fontSize: 22, fontWeight: '700', marginVertical: space.md, color: colors.text },
  row: { backgroundColor: colors.card, padding: space.md, borderRadius: 12, marginBottom: space.sm, borderColor: colors.line, borderWidth: 1 },
  name: { fontSize: 17, fontWeight: '600', color: colors.text },
  sub: { color: colors.muted, marginTop: 2 },
});
