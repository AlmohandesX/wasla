/**
 * Client-side checks that mirror the backend's StartRenewalRequest, so most mistakes are caught before a
 * request is made. The server still decides; these only save a round trip.
 */

/** Turkish national ID: 11 digits, first not 0, and the two official check digits (App\Rules\TcKimlik). */
export function isValidTckn(id: string): boolean {
  if (!/^[1-9][0-9]{10}$/.test(id)) return false;
  const d = id.split('').map(Number);
  const tenth = ((((d[0] + d[2] + d[4] + d[6] + d[8]) * 7 - (d[1] + d[3] + d[5] + d[7])) % 10) + 10) % 10;
  if (tenth !== d[9]) return false;
  return d.slice(0, 10).reduce((a, b) => a + b, 0) % 10 === d[10];
}

/** At least two words: iyzico needs a surname as well as a name. */
export function isFullName(name: string): boolean {
  return /\S\s+\S/u.test(name.trim());
}

/** A Turkish mobile number in any common spelling, or a number with its own country code (not +90). */
export function isValidBillingPhone(phone: string): boolean {
  const digits = phone.replace(/[\s()-]/g, '');
  if (/^(?:\+?90|0)?5\d{9}$/.test(digits)) return true;
  return /^\+[1-9]\d{7,14}$/.test(digits) && !digits.startsWith('+90');
}
