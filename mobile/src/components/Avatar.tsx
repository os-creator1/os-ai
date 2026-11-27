import React from 'react';
import { StyleSheet, View } from 'react-native';
import { colors } from '../theme';
import { Icon } from './Icon';
import { Text } from './Text';

/** Soft background / ink pairs the design rotates through for avatars. */
const TONES = [
  { bg: colors.brandSoft, fg: colors.brandPressed },
  { bg: colors.infoSoft, fg: colors.info },
  { bg: colors.successSoft, fg: colors.success },
  { bg: colors.warningSoft, fg: colors.warning },
  { bg: colors.violetSoft, fg: colors.violet },
];

/** "Ava Martinez" → "AM"; a phone number or empty name has no initials. */
export function initials(name: string | null | undefined): string {
  if (!name) return '';
  const words = name.trim().split(/\s+/).filter((w) => /\p{L}/u.test(w[0] ?? ''));
  if (words.length === 0) return '';
  const first = words[0][0];
  const last = words.length > 1 ? words[words.length - 1][0] : '';
  return (first + last).toUpperCase();
}

/** Same name → same colour, everywhere. */
export function toneFor(key: string) {
  let h = 0;
  for (let i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) | 0;
  return TONES[Math.abs(h) % TONES.length];
}

type Props = { name: string | null | undefined; size?: number; tone?: { bg: string; fg: string } };

/** Initials avatar; a contact without a name (only a number) gets a neutral person glyph. */
export function Avatar({ name, size = 46, tone }: Props) {
  const text = initials(name);
  const t = tone ?? (text ? toneFor(name ?? '') : { bg: colors.neutralSoft, fg: colors.neutral });
  return (
    <View style={[s.av, { width: size, height: size, borderRadius: size / 2, backgroundColor: t.bg }]} accessibilityElementsHidden importantForAccessibility="no-hide-descendants">
      {text
        ? <Text weight="700" color={t.fg} size={Math.round(size * 0.35)}>{text}</Text>
        : <Icon name="user" size={Math.round(size * 0.42)} color={t.fg} />}
    </View>
  );
}

const s = StyleSheet.create({ av: { alignItems: 'center', justifyContent: 'center', flex: 0 } });
