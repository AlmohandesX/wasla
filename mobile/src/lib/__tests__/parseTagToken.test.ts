import { parseTagToken } from '../parseTagToken';

const BASE = 'https://wasla.example/t';
const TOKEN = 'aB3dE6gH9jK2mN5p';

test('extracts the token from a valid tag URL', () => {
  expect(parseTagToken(`${BASE}/${TOKEN}`, BASE, false)).toBe(TOKEN);
  expect(parseTagToken(`  ${BASE}/${TOKEN}\n`, BASE, false)).toBe(TOKEN);
  expect(parseTagToken(`HTTPS://WASLA.example/t/${TOKEN}`, BASE, false)).toBe(TOKEN);
});

test('tolerates one trailing slash, as the backend route does, and a base given with one', () => {
  expect(parseTagToken(`${BASE}/${TOKEN}/`, BASE, false)).toBe(TOKEN);
  expect(parseTagToken(`${BASE}/${TOKEN}`, `${BASE}/`, false)).toBe(TOKEN);
  expect(parseTagToken(`${BASE}/${TOKEN}//`, BASE, false)).toBeNull();
});

test('returns null for another domain, even one containing our domain', () => {
  expect(parseTagToken(`https://wasla.example.evil.test/t/${TOKEN}`, BASE, false)).toBeNull();
  expect(parseTagToken(`https://evil.test/https://wasla.example/t/${TOKEN}`, BASE, false)).toBeNull();
  expect(parseTagToken(`https://wasla.example@evil.test/t/${TOKEN}`, BASE, false)).toBeNull();
  expect(parseTagToken(`https://user:pw@wasla.example/t/${TOKEN}`, BASE, false)).toBeNull();
  expect(parseTagToken(`https://wasla.example:8443/t/${TOKEN}`, BASE, false)).toBeNull();
});

test('returns null for plain text, non-https in production, or a wrong path', () => {
  expect(parseTagToken(TOKEN, BASE, false)).toBeNull();
  expect(parseTagToken('hello', BASE, false)).toBeNull();
  expect(parseTagToken('', BASE, false)).toBeNull();
  expect(parseTagToken(`http://wasla.example/t/${TOKEN}`, BASE, false)).toBeNull();
  expect(parseTagToken(`http://localhost:8000/t/${TOKEN}`, 'http://localhost:8000/t', false)).toBeNull();
  expect(parseTagToken(`${BASE}/x/${TOKEN}`, BASE, false)).toBeNull();
  expect(parseTagToken(`https://wasla.example/${TOKEN}`, BASE, false)).toBeNull();
  expect(parseTagToken(`https://wasla.example/t/${TOKEN}/chat`, BASE, false)).toBeNull();
  expect(parseTagToken(`${BASE}/${TOKEN}?x=1`, BASE, false)).toBeNull();
  expect(parseTagToken(`${BASE}/${TOKEN}#x`, BASE, false)).toBeNull();
});

test('accepts plain http only in a development build and only for a local address', () => {
  expect(parseTagToken(`http://localhost:8000/t/${TOKEN}`, 'http://localhost:8000/t', true)).toBe(TOKEN);
  expect(parseTagToken(`http://192.168.1.10:8000/t/${TOKEN}`, 'http://192.168.1.10:8000/t', true)).toBe(TOKEN);
  expect(parseTagToken(`http://wasla.example/t/${TOKEN}`, 'http://wasla.example/t', true)).toBeNull();
  expect(parseTagToken(`http://localhost:8000/t/${TOKEN}`, BASE, true)).toBeNull(); // scheme differs from the base
});

test('returns null for a token that is not 16 base62 characters', () => {
  expect(parseTagToken(`${BASE}/abc`, BASE, false)).toBeNull();
  expect(parseTagToken(`${BASE}/${TOKEN}x`, BASE, false)).toBeNull();
  expect(parseTagToken(`${BASE}/aB3dE6gH9jK2mN5-`, BASE, false)).toBeNull();
  expect(parseTagToken(`${BASE}/aB3dE6gH9jK2mN%35`, BASE, false)).toBeNull();
  expect(parseTagToken(`${BASE}/`, BASE, false)).toBeNull();
});

test('returns null when the configured base is missing or unusable', () => {
  expect(parseTagToken(`${BASE}/${TOKEN}`, '', false)).toBeNull();
  expect(parseTagToken(`${BASE}/${TOKEN}`, 'nonsense', false)).toBeNull();
});
