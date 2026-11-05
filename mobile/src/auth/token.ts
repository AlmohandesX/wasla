import * as SecureStore from 'expo-secure-store';

// The only place the API token is stored. Never AsyncStorage, never logged.
const KEY = 'wasla.token';

export function getToken(): Promise<string | null> {
  return SecureStore.getItemAsync(KEY);
}

export function setToken(token: string): Promise<void> {
  return SecureStore.setItemAsync(KEY, token);
}

export function clearToken(): Promise<void> {
  return SecureStore.deleteItemAsync(KEY);
}
