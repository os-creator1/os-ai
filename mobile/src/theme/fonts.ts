import type { FontWeight } from './index';

/**
 * Geist, loaded at runtime with expo-font (already linked in the native build — no new native module).
 * Android cannot pick a weight from one family name, so each weight is registered as its own family and text asks for
 * the family that matches its weight. Until the fonts have loaded (or if they fail to), text falls back to the system
 * font: no screen ever references a family that is not registered.
 */
const FAMILY: Record<FontWeight, string> = {
  '400': 'Geist_400Regular',
  '500': 'Geist_500Medium',
  '600': 'Geist_600SemiBold',
  '700': 'Geist_700Bold',
  '800': 'Geist_800ExtraBold',
};

export const fontSources = {
  Geist_400Regular: require('@expo-google-fonts/geist/400Regular/Geist_400Regular.ttf'),
  Geist_500Medium: require('@expo-google-fonts/geist/500Medium/Geist_500Medium.ttf'),
  Geist_600SemiBold: require('@expo-google-fonts/geist/600SemiBold/Geist_600SemiBold.ttf'),
  Geist_700Bold: require('@expo-google-fonts/geist/700Bold/Geist_700Bold.ttf'),
  Geist_800ExtraBold: require('@expo-google-fonts/geist/800ExtraBold/Geist_800ExtraBold.ttf'),
};

let ready = false;
export const fontsReady = () => ready;
export function setFontsReady(value: boolean) { ready = value; }

/** Style fragment for a weight: the Geist family when loaded, otherwise the system font with that weight. */
export function fontFamilyFor(weight: FontWeight): { fontFamily?: string; fontWeight: FontWeight | 'normal' } {
  return ready ? { fontFamily: FAMILY[weight], fontWeight: 'normal' } : { fontWeight: weight };
}
