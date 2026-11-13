/** The first word of a full name ("Ayşe Nur Yılmaz" → "Ayşe"), or null when there is no name to show. */
export function firstName(fullName: string | null | undefined): string | null {
  const first = (fullName ?? '').trim().split(/\s+/)[0];
  return first ? first : null;
}
