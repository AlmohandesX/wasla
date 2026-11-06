import { isFullName, isValidBillingPhone, isValidTckn } from '../billing';
import { isAllowedCheckoutUrl } from '../checkoutUrl';

test('TCKN checksum accepts a valid number and refuses the rest', () => {
  expect(isValidTckn('10000000146')).toBe(true);
  expect(isValidTckn('10000000147')).toBe(false); // wrong check digit
  expect(isValidTckn('00000000000')).toBe(false); // leading zero
  expect(isValidTckn('1000000014')).toBe(false); // too short
  expect(isValidTckn('1000000014a')).toBe(false);
});

test('billing name needs two words', () => {
  expect(isFullName('Ayse')).toBe(false);
  expect(isFullName('  Ayse  ')).toBe(false);
  expect(isFullName('Ayse Yilmaz')).toBe(true);
});

test('billing phone mirrors the backend', () => {
  expect(isValidBillingPhone('0532 123 45 67')).toBe(true);
  expect(isValidBillingPhone('+90 532 123 45 67')).toBe(true);
  expect(isValidBillingPhone('5321234567')).toBe(true);
  expect(isValidBillingPhone('+44 7911 123456')).toBe(true);
  expect(isValidBillingPhone('0212 123 45 67')).toBe(false);
  expect(isValidBillingPhone('')).toBe(false);
});

describe('checkout URL', () => {
  const api = 'http://192.168.1.10:8000/api/v1';
  test('https is allowed', () => expect(isAllowedCheckoutUrl('https://pay.example.com/x', api, false)).toBe(true));
  test('http only to the configured local host in dev', () => {
    expect(isAllowedCheckoutUrl('http://192.168.1.10:8000/fake-gateway/checkout/W1', api, true)).toBe(true);
    expect(isAllowedCheckoutUrl('http://192.168.1.10:8000/fake-gateway/checkout/W1', api, false)).toBe(false);
    expect(isAllowedCheckoutUrl('http://192.168.1.11:8000/x', api, true)).toBe(false);
    expect(isAllowedCheckoutUrl('http://evil.com/x', api, true)).toBe(false);
  });
  test('other schemes and junk are refused', () => {
    expect(isAllowedCheckoutUrl('javascript:alert(1)', api, true)).toBe(false);
    expect(isAllowedCheckoutUrl('intent://x', api, true)).toBe(false);
    expect(isAllowedCheckoutUrl('https://user:pw@pay.example.com/', api, true)).toBe(false);
    expect(isAllowedCheckoutUrl(undefined, api, true)).toBe(false);
    expect(isAllowedCheckoutUrl('not a url', api, true)).toBe(false);
  });
});
