import Constants from 'expo-constants';

/** The Laravel origin, e.g. https://app.example.com — set per build via EXPO_PUBLIC_API_URL. */
export function apiBaseUrl(): string {
  const raw = String(process.env.EXPO_PUBLIC_API_URL ?? Constants.expoConfig?.extra?.apiUrl ?? '').replace(/\/+$/, '');
  return raw;
}

/** Production builds must use TLS; plain http is allowed only in a development bundle (local Laravel). */
export function assertSecureOrigin(url: string, dev: boolean = __DEV__): void {
  if (url === '') throw new Error('EXPO_PUBLIC_API_URL is not set');
  if (!/^https:\/\//i.test(url) && !(dev && /^http:\/\//i.test(url))) {
    throw new Error('The API origin must be https');
  }
}
