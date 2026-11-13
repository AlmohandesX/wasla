import { useSyncExternalStore } from 'react';
import * as SecureStore from '../lib/secureStorage';

import en from './en.json';
import tr from './tr.json';

export type Locale = 'tr' | 'en';
export const LOCALES: Locale[] = ['en', 'tr'];

const dictionaries: Record<Locale, Record<string, string>> = { tr, en };
const STORE_KEY = 'wasla.locale';

let current: Locale = 'en'; // English is the default; Turkish is the second language.
const listeners = new Set<() => void>();

export function getLocale(): Locale {
  return current;
}

export function setLocale(locale: Locale): void {
  if (locale === current) return;
  current = locale;
  listeners.forEach((l) => l());
  // Persisting the choice is best effort; the language still switches if storage fails.
  SecureStore.setItemAsync(STORE_KEY, locale).catch(() => {});
}

/** Restores the saved language at startup. */
export async function loadLocale(): Promise<void> {
  try {
    const saved = await SecureStore.getItemAsync(STORE_KEY);
    if (saved === 'tr' || saved === 'en') {
      current = saved;
      listeners.forEach((l) => l());
    }
  } catch {
    // Keep the default.
  }
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

/** Re-renders the calling component when the language changes. */
export function useLocale(): Locale {
  return useSyncExternalStore(subscribe, getLocale, getLocale);
}

export function hasKey(key: string): boolean {
  return key in dictionaries[current] || key in dictionaries.en;
}

export function t(key: string, params?: Record<string, string | number>): string {
  const template = dictionaries[current][key] ?? dictionaries.en[key] ?? key;
  if (!params) return template;
  return template.replace(/\{(\w+)\}/g, (match, name: string) =>
    name in params ? String(params[name]) : match,
  );
}
