import en from '../en.json';
import tr from '../tr.json';
import { getLocale, hasKey, setLocale, t } from '../index';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));

afterEach(() => setLocale('en'));

test('tr.json and en.json have identical keys', () => {
  expect(Object.keys(tr).sort()).toEqual(Object.keys(en).sort());
});

test('no translation is empty and placeholders match across languages', () => {
  const placeholders = (s: string) => (s.match(/\{\w+\}/g) ?? []).sort();
  for (const key of Object.keys(tr) as (keyof typeof tr)[]) {
    expect(tr[key].trim()).not.toBe('');
    expect(en[key].trim()).not.toBe('');
    expect(placeholders(en[key])).toEqual(placeholders(tr[key]));
  }
});

test('English is the default', () => {
  expect(getLocale()).toBe('en');
  expect(t('home.title', { name: 'Ada' })).toBe('Hello, Ada');
});

test('Turkish is the second language and keeps its special characters', () => {
  setLocale('tr');
  expect(t('login.forgot')).toBe('Şifremi unuttum');
  expect(t('errors.invalid_credentials')).toContain('şifre');
});

test('switches language and interpolates params', () => {
  setLocale('en');
  expect(t('home.title', { name: 'Ada' })).toBe('Hello, Ada');
  setLocale('tr');
  expect(t('home.title', { name: 'Işıl' })).toBe('Merhaba, Işıl');
});

test('an unknown key falls back to the key itself', () => {
  expect(hasKey('nope')).toBe(false);
  expect(t('nope')).toBe('nope');
});
