import * as SecureStore from 'expo-secure-store';

// The only place the API token is stored. Never AsyncStorage, never logged.
const KEY = 'wasla.token';

// The current token lives in memory too and changes synchronously, before the (slower) secure-store write
// finishes. Code that needs "is this still the token I used?" compares against this, not against storage.
let memory: { value: string | null } | undefined;
let generation = 0;

/** Increases every time the token is set or cleared. */
export function tokenGeneration(): number {
  return generation;
}

export async function getToken(): Promise<string | null> {
  if (memory) return memory.value;
  const stored = await SecureStore.getItemAsync(KEY);
  // A write that happened while we were reading wins over what we read.
  if (!memory) memory = { value: stored };
  return memory.value;
}

export async function setToken(token: string): Promise<void> {
  memory = { value: token };
  generation++;
  await SecureStore.setItemAsync(KEY, token);
}

export async function clearToken(): Promise<void> {
  memory = { value: null };
  generation++;
  await SecureStore.deleteItemAsync(KEY);
}
