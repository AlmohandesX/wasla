import { normalizePhone } from '../phone';

test('Turkish numbers normalise to +90', () => {
  expect(normalizePhone('0532 123 45 67')).toBe('+905321234567');
  expect(normalizePhone('532-123-45-67')).toBe('+905321234567');
  expect(normalizePhone('+90 (532) 123 45 67')).toBe('+905321234567');
  expect(normalizePhone('00905321234567')).toBe('+905321234567');
});

test('international E.164 numbers pass, malformed ones do not', () => {
  expect(normalizePhone('+44 20 7946 0958')).toBe('+442079460958');
  expect(normalizePhone('+9053212345')).toBeNull();
  expect(normalizePhone('0632 123 45 67')).toBeNull();
  expect(normalizePhone('12345')).toBeNull();
  expect(normalizePhone('abc')).toBeNull();
  expect(normalizePhone('')).toBeNull();
});
