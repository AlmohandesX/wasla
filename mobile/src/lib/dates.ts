const TIME_ZONE = 'Europe/Istanbul';

const pad = (n: number) => String(n).padStart(2, '0');

function isRealDate(y: number, m: number, d: number): boolean {
  const date = new Date(Date.UTC(y, m - 1, d));
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d;
}

/** "05.03.2027", "5/3/2027" or "2027-03-05" as "2027-03-05" (what the API takes), or null when it is not a real date. */
export function parseDateInput(text: string): string | null {
  const s = text.trim();
  let y: number, m: number, d: number;
  let match = /^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$/.exec(s);
  if (match) {
    [d, m, y] = [Number(match[1]), Number(match[2]), Number(match[3])];
  } else if ((match = /^(\d{4})-(\d{1,2})-(\d{1,2})$/.exec(s))) {
    [y, m, d] = [Number(match[1]), Number(match[2]), Number(match[3])];
  } else {
    return null;
  }
  return isRealDate(y, m, d) ? `${y}-${pad(m)}-${pad(d)}` : null;
}

/** A stored calendar date shown as day.month.year. It is a date, not a moment, so no zone shift applies. */
export function formatDueDate(iso: string): string {
  const [y, m, d] = iso.split('-');
  return `${d}.${m}.${y}`;
}

/** Today's date in Istanbul as "YYYY-MM-DD" (the zone the backend checks "not in the past" against). */
export function todayInIstanbul(now: Date = new Date()): string {
  try {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: TIME_ZONE, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now);
    if (/^\d{4}-\d{2}-\d{2}$/.test(parts)) return parts;
  } catch {
    // fall through
  }
  // No zone data: Istanbul is UTC+3 all year.
  const shifted = new Date(now.getTime() + 3 * 3600 * 1000);
  return `${shifted.getUTCFullYear()}-${pad(shifted.getUTCMonth() + 1)}-${pad(shifted.getUTCDate())}`;
}
