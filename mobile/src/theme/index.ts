/**
 * MotionGrove mobile design tokens. Source of truth: the approved mobile design ("Mobile design system" board).
 * Same red, borders and warm neutrals as web Conversations. Screens read colours, spacing, radii and type from here —
 * never hard-coded values.
 */
export const palette = {
  brand: '#B4514A',
  brandPressed: '#8F3B35',
  brandSoft: '#F8E9E6',
  background: '#F6F4F1',
  surface: '#FFFFFF',
  border: '#E9E4DF',
  textPrimary: '#1F1A19',
  textSecondary: '#6B625E',
  success: '#17693F',
  warning: '#7A4A05',
  danger: '#A3352A',
  unread: '#B4514A',
} as const;

export const colors = {
  ...palette,
  onBrand: '#FFFFFF',
  /** Search field and segmented-control track. */
  fill: '#ECE8E3',
  /** Hairline between rows inside a group / list. */
  divider: '#F0EBE6',
  /** Outline of inputs and secondary buttons. */
  borderStrong: '#E1DBD5',
  /** Outline of grouped cards. */
  borderSoft: '#EDE8E3',
  /** Unselected segment label, business name in the header. */
  textTertiary: '#5E5652',
  /** Form labels, neutral icon tiles. */
  textInk: '#4A4340',
  placeholder: '#7A716C',
  skeleton: '#ECE7E2',
  dayChip: '#EEEAE5',
  composerFill: '#FBF9F7',
  grabber: '#D9D2CB',
  neutralTile: '#F1EDE9',
  dangerSoft: '#FBEDEB',
  successSoft: '#E3F3EA',
  warningSoft: '#FDF1DC',
  info: '#1F5682',
  infoSoft: '#E4EEF6',
  neutral: '#4A4340',
  neutralSoft: '#EFEBE7',
  violet: '#5B3A7A',
  violetSoft: '#EFE6F6',
  /** Notification-permission banner (soft brand tint). */
  promoBg: '#FBF0EE',
  promoText: '#4A2622',
  scrim: 'rgba(31,26,25,0.42)',
  // Legacy aliases kept so screens not restyled yet (LoginScreen, pending the 2FA branch) keep compiling.
  bg: palette.background,
  card: palette.surface,
  text: palette.textPrimary,
  muted: palette.textSecondary,
  line: palette.border,
  brandText: '#FFFFFF',
} as const;

/** Spacing scale 4 · 8 · 12 · 16 · 24 · 32. */
export const space = { xs: 4, sm: 8, mid: 12, md: 16, lg: 24, xl: 32 } as const;

/** Corner radii 10 / 14 / 22 (plus the full pill). */
export const radius = { sm: 10, md: 14, lg: 22, field: 12, button: 13, pill: 999 } as const;

/** Minimum touch target (pt). */
export const touch = 44;

export type FontWeight = '400' | '500' | '600' | '700' | '800';

/** Type scale from the design: size / weight (+ line height where the design sets one). */
export const type = {
  largeTitle: { fontSize: 30, weight: '800', letterSpacing: -0.6 },
  title: { fontSize: 22, weight: '800' },
  headline: { fontSize: 17, weight: '700' },
  body: { fontSize: 16, weight: '400' },
  subhead: { fontSize: 14.5, weight: '500' },
  caption: { fontSize: 12.5, weight: '600' },
} as const satisfies Record<string, { fontSize: number; weight: FontWeight; letterSpacing?: number }>;

export type TypeVariant = keyof typeof type;

export const shadow = {
  segment: { shadowColor: '#1F1A19', shadowOpacity: 0.12, shadowRadius: 3, shadowOffset: { width: 0, height: 1 }, elevation: 1 },
  sheet: { shadowColor: '#000000', shadowOpacity: 0.12, shadowRadius: 30, shadowOffset: { width: 0, height: -10 }, elevation: 12 },
  fab: { shadowColor: '#B4514A', shadowOpacity: 0.35, shadowRadius: 20, shadowOffset: { width: 0, height: 8 }, elevation: 6 },
} as const;

export { fontFamilyFor, setFontsReady, fontsReady } from './fonts';
