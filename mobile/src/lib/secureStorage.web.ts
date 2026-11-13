// Web build only. A browser has no keychain, so values go in sessionStorage: they survive a page reload but are
// dropped when the tab closes, which keeps a signed-in token's lifetime short. Never localStorage for the token.
// Same signatures as the native module so callers do not change.

function store(): Storage {
  if (typeof window === 'undefined' || !window.sessionStorage) throw new Error('Storage is not available');
  return window.sessionStorage;
}

export async function getItemAsync(key: string): Promise<string | null> {
  return store().getItem(key);
}

export async function setItemAsync(key: string, value: string): Promise<void> {
  store().setItem(key, value);
}

export async function deleteItemAsync(key: string): Promise<void> {
  store().removeItem(key);
}
