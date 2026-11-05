import * as SecureStore from 'expo-secure-store';

import { unregisterDeviceToken } from '../api/push';

const KEY = 'wasla.pushToken';

/** The token this install registered with the backend, kept so sign-out can remove it. In memory it also stops repeat registration. */
let registered: string | null = null;

export function alreadyRegistered(token: string): boolean {
  return registered === token;
}

export async function rememberToken(token: string): Promise<void> {
  registered = token;
  try {
    await SecureStore.setItemAsync(KEY, token);
  } catch {
    // Without storage the in-memory copy still serves this run.
  }
}

/** Best effort, called while the session is still valid: the owner is signing out whether or not this works. */
export async function unregisterStoredToken(): Promise<void> {
  let token = registered;
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
