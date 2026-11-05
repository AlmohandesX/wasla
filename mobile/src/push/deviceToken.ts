import * as SecureStore from 'expo-secure-store';

import { unregisterDeviceToken } from '../api/push';

const KEY = 'wasla.pushToken';

/**
 * What this run has registered with the backend, and for whom. Keyed by user so the next account on the same
 * phone registers again; reset on any session end (explicit sign-out or a rejected token).
 */
let registered: { userId: number; token: string } | null = null;

export function alreadyRegistered(userId: number, token: string): boolean {
  return registered?.userId === userId && registered.token === token;
}

export function resetRegistration(): void {
  registered = null;
}

export async function rememberToken(userId: number, token: string): Promise<void> {
  registered = { userId, token };
  try {
    await SecureStore.setItemAsync(KEY, token);
  } catch {
    // Without storage the in-memory copy still serves this run.
  }
}

/** Best effort, called while the session is still valid: the owner is signing out whether or not this works. */
export async function unregisterStoredToken(): Promise<void> {
  let token = registered?.token ?? null;
  try {
    token = token ?? (await SecureStore.getItemAsync(KEY));
  } catch {
    // ignore
  }
  registered = null;
  if (!token) return;
  try {
    await unregisterDeviceToken(token);
    await SecureStore.deleteItemAsync(KEY);
  } catch {
    // The backend also drops tokens Expo reports as dead.
  }
}
