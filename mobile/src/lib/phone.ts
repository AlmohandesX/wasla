/**
 * Mirrors the backend's App\Rules\PhoneNumber::normalize, so the form can refuse a number before the server does.
 * Returns the E.164 form (+905321234567), or null when the number is not valid.
 */
export function normalizePhone(raw: string): string | null {
  let number = raw.replace(/[\s\-().]/g, '');
  if (number.startsWith('00')) number = `+${number.slice(2)}`;

  const turkish = number.match(/^0?([2-5]\d{9})$/);
  if (turkish) return `+90${turkish[1]}`;

  if (!/^\+[1-9]\d{7,14}$/.test(number)) return null;
  if (number.startsWith('+90') && !/^\+90[2-5]\d{9}$/.test(number)) return null;
  return number;
}
