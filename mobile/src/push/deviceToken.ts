import * as SecureStore from 'expo-secure-store';

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

/**
 * Hands over this phone's push token for the logout request, which drops it on the server together with the
 * session, and forgets it locally. Null when this phone never registered one.
 */
export async function takeStoredToken(): Promise<string | null> {
  let token = registered?.token ?? null;
  registered = null;
  try {
    token = token ?? (await SecureStore.getItemAsync(KEY));
    await SecureStore.deleteItemAsync(KEY);
  } catch {
    // ignore
  }
  return token;
}

/**
 * After the account is deleted the server has already dropped this phone's push token with it, so there is
 * nothing to unregister: only the local copy goes.
 */
export async function forgetStoredToken(): Promise<void> {
  registered = null;
  try {
    await SecureStore.deleteItemAsync(KEY);
  } catch {
    // ignore
  }
}
