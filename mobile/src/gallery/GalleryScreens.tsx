import React from 'react';
import { ScrollView, StyleSheet, View } from 'react-native';
import type { NativeStackScreenProps } from '@react-navigation/native-stack';
import { Banner, ListGroup, ListRow, SectionHeader, Text } from '../components';
import type { RootStack } from '../navigation/types';
import { useBottomInset } from '../screens/ui';
import { colors, space } from '../theme';
import { NavBar } from './parts';
import { GALLERY, galleryEntry } from './registry';

/** Dev-only index of the planned screens. Reached from Settings → Design gallery in development builds only. */
export function DesignGalleryScreen({ navigation }: NativeStackScreenProps<RootStack, 'DesignGallery'>) {
  const bottom = useBottomInset();
  const groups = [...new Set(GALLERY.map((e) => e.group))];
  return (
    <View testID="design-gallery" style={s.wrap}>
      <NavBar title="Design gallery" onLeft={() => navigation.goBack()} />
      <ScrollView contentContainerStyle={[s.content, { paddingBottom: space.lg + bottom }]}>
        <Banner tone="info" message="Static previews of planned screens with fake data. Nothing here calls the API." />
        {groups.map((g) => (
          <View key={g} style={s.section}>
            <SectionHeader title={g} />
            <ListGroup>
              {GALLERY.filter((e) => e.group === g).map((e) => (
                <ListRow key={e.id} title={e.title} subtitle={e.mirrors} chevron onPress={() => navigation.navigate('DesignPreview', { id: e.id })} />
              ))}
            </ListGroup>
          </View>
        ))}
      </ScrollView>
    </View>
  );
}

/** Renders one gallery entry full screen. */
export function DesignPreviewScreen({ navigation, route }: NativeStackScreenProps<RootStack, 'DesignPreview'>) {
  const entry = galleryEntry(route.params.id);
  if (!entry) {
    return (
      <View style={s.wrap}>
        <NavBar title="Not found" onLeft={() => navigation.goBack()} />
        <Text align="center" color={colors.textSecondary}>No preview called “{route.params.id}”.</Text>
      </View>
    );
  }
  const Preview = entry.component;
  return <View testID={`preview-${entry.id}`} style={s.fill}><Preview onBack={() => navigation.goBack()} /></View>;
}

const s = StyleSheet.create({
  wrap: { flex: 1, backgroundColor: colors.background },
  fill: { flex: 1 },
  content: { paddingHorizontal: space.md, gap: 20, paddingTop: space.sm },
  section: { gap: space.sm },
});
