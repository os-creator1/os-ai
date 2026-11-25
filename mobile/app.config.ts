import type { ExpoConfig } from 'expo/config';

/**
 * MotionGrove Mobile. The API origin is the ONLY environment-specific value and is public by nature.
 * No provider key, Telnyx credential or SIP secret is ever read here or bundled: the phone only ever holds a
 * short-lived Sanctum token for this one platform (see docs/mobile/ARCHITECTURE.md).
 */
const config: ExpoConfig = {
  name: 'MotionGrove',
  slug: 'motiongrove-mobile',
  scheme: 'motiongrove',
  version: '0.1.0',
  orientation: 'portrait',
  icon: './assets/icon.png',
  userInterfaceStyle: 'light',
  ios: {
    bundleIdentifier: 'com.motiongrove.mobile',
    supportsTablet: false,
    infoPlist: {
      ITSAppUsesNonExemptEncryption: false,
      NSMicrophoneUsageDescription: 'MotionGrove uses the microphone for voice calls with your customers.',
    },
  },
  android: {
    package: 'com.motiongrove.mobile',
    adaptiveIcon: {
      backgroundColor: '#E6F4FE',
      foregroundImage: './assets/android-icon-foreground.png',
      backgroundImage: './assets/android-icon-background.png',
      monochromeImage: './assets/android-icon-monochrome.png',
    },
    permissions: ['android.permission.POST_NOTIFICATIONS'],
  },
  plugins: [
    'expo-dev-client',
    'expo-secure-store',
    ['expo-notifications', { color: '#1F6F5C' }],
    ['expo-build-properties', { android: { usesCleartextTraffic: process.env.MOBILE_DEV_CLEARTEXT === '1' } }],
  ],
  extra: {
    apiUrl: process.env.EXPO_PUBLIC_API_URL ?? '',
  },
};

export default config;
