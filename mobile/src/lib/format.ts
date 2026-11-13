import { getLocale } from '../i18n';

const TIME_ZONE = 'Europe/Istanbul';

/** A UTC ISO timestamp shown in Istanbul time. Falls back to the device zone if the runtime lacks that zone. */
export function formatDateTime(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const locale = getLocale() === 'tr' ? 'tr-TR' : 'en-GB';
  const options: Intl.DateTimeFormatOptions = { dateStyle: 'medium', timeStyle: 'short' };
  try {
    return date.toLocaleString(locale, { ...options, timeZone: TIME_ZONE });
  } catch {
    return date.toLocaleString(locale, options);
  }
}

/** A UTC ISO timestamp as a calendar date in Istanbul time (for subscription expiry). */
export function formatDate(iso: string): string {
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  const locale = getLocale() === 'tr' ? 'tr-TR' : 'en-GB';
  try {
    return date.toLocaleDateString(locale, { dateStyle: 'long', timeZone: TIME_ZONE });
  } catch {
    return date.toLocaleDateString(locale, { dateStyle: 'long' });
  }
}
