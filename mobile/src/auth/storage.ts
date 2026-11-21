import * as SecureStore from 'expo-secure-store';

/** Keychain (iOS) / Keystore-backed storage (Android). The Sanctum token never touches plain AsyncStorage. */
const K = { token: 'mg.token', expires: 'mg.expires', scope: 'mg.scope', install: 'mg.install' };

export type Stored = { token: string; expiresAt: string } | null;

export async function loadSession(): Promise<Stored> {
  const [token, expiresAt] = await Promise.all([SecureStore.getItemAsync(K.token), SecureStore.getItemAsync(K.expires)]);
  if (!token || !expiresAt || new Date(expiresAt).getTime() <= Date.now()) return null;
  return { token, expiresAt };
}
export async function saveSession(token: string, expiresAt: string) {
  await SecureStore.setItemAsync(K.token, token);
  await SecureStore.setItemAsync(K.expires, expiresAt);
}
export async function clearSession() {
  await Promise.all([K.token, K.expires, K.scope].map((k) => SecureStore.deleteItemAsync(k)));
}
export async function loadScope(): Promise<{ workspaceUid: string; businessUid: string } | null> {
  const raw = await SecureStore.getItemAsync(K.scope);
  try { return raw ? JSON.parse(raw) : null; } catch { return null; }
}
export async function saveScope(scope: { workspaceUid: string; businessUid: string }) {
  await SecureStore.setItemAsync(K.scope, JSON.stringify(scope));
}
/** A random per-install id (not a hardware id): lets the server keep one live token per phone. */
export async function installationId(): Promise<string> {
  let id = await SecureStore.getItemAsync(K.install);
  if (!id) {
    id = `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 12)}-${Math.random().toString(36).slice(2, 12)}`;
    await SecureStore.setItemAsync(K.install, id);
  }
  return id;
}
