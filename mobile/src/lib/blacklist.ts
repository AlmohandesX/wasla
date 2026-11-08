/**
 * Chat profanity filter. Blacklisted words in a message are replaced with "***" before it is sent.
 *
 * How to fill the lists:
 * - One entry per word or abbreviation, in any case ("WTF" and "wtf" are the same entry).
 * - End an entry with "*" to also match every word that starts with it. Use this for Turkish, where suffixes
 *   are added to the stem (an entry "stem*" catches "stemler", "stemin", ...), and for Arabic words that take
 *   endings. Without "*" only the exact whole word matches, so harmless words that merely contain a
 *   blacklisted one are left alone.
 * - Arabic entries can be written with or without short vowels (tashkeel) and with any alef form; both sides
 *   are normalised before comparing (see `normalize`).
 * - Matching is whole-word only: a phrase of several words is not supported as one entry.
 */
export const BLACKLIST = {
  ar: ['badword_ar', 'badabbr_ar'],
  en: ['badword_en', 'badabbr_en'],
  tr: ['badword_tr', 'badstem_tr*'],
} as const satisfies Record<'ar' | 'en' | 'tr', readonly string[]>;

export type BlacklistLanguage = keyof typeof BLACKLIST;

/** Every entry from every language, as written above. */
export const BLACKLISTED_WORDS: readonly string[] = Object.values(BLACKLIST).flat();

export const CENSOR = '***';

// Arabic short vowels, shadda, sukun, superscript alef, Quranic marks, and the tatweel (kashida) stretch.
const ARABIC_MARKS = /[ؐ-ًؚ-ٰٟۖ-ۭـ]/g;

/**
 * Folds a word for comparison: lower case (with the Turkish dotted capital İ treated as i), Arabic marks and
 * tatweel removed, and Arabic letter variants unified (أ إ آ ٱ → ا, ى → ي, ة → ه, ؤ → و, ئ → ي).
 * Turkish letters such as ş, ğ, ç, ö, ü and ı are kept as they are, because folding them turns ordinary words
 * into blacklisted ones.
 */
export function normalize(word: string): string {
  return word
    .normalize('NFC')
    .replace(/İ/g, 'i')
    .toLowerCase()
    .replace(ARABIC_MARKS, '')
    .replace(/[أإآٱ]/g, 'ا')
    .replace(/ى/g, 'ي')
    .replace(/ة/g, 'ه')
    .replace(/ؤ/g, 'و')
    .replace(/ئ/g, 'ي');
}

type Matcher = { exact: Set<string>; prefixes: string[] };

function buildMatcher(entries: readonly string[]): Matcher {
  const exact = new Set<string>();
  const prefixes: string[] = [];
  for (const entry of entries) {
    const isPrefix = entry.endsWith('*');
    const word = normalize(isPrefix ? entry.slice(0, -1) : entry).trim();
    if (!word) continue;
    if (isPrefix) prefixes.push(word);
    else exact.add(word);
  }
  return { exact, prefixes };
}

const DEFAULT_MATCHER = buildMatcher(BLACKLISTED_WORDS);

// A word is a run of letters, digits, underscores or combining marks (so Arabic tashkeel stays inside its
// word), plus the tatweel, which writers put inside words.
const WORD = /[\p{L}\p{M}\p{N}_ـ]+/gu;

function isBlacklisted(word: string, matcher: Matcher): boolean {
  const folded = normalize(word);
  return matcher.exact.has(folded) || matcher.prefixes.some((p) => folded.startsWith(p));
}

/** True when `text` contains at least one blacklisted word. */
export function containsBlacklisted(text: string, entries: readonly string[] = BLACKLISTED_WORDS): boolean {
  const matcher = entries === BLACKLISTED_WORDS ? DEFAULT_MATCHER : buildMatcher(entries);
  for (const [word] of text.matchAll(WORD)) if (isBlacklisted(word, matcher)) return true;
  return false;
}

/** `text` with every blacklisted word replaced by "***". Everything else, spacing included, is unchanged. */
export function censor(text: string, entries: readonly string[] = BLACKLISTED_WORDS): string {
  const matcher = entries === BLACKLISTED_WORDS ? DEFAULT_MATCHER : buildMatcher(entries);
  return text.replace(WORD, (word) => (isBlacklisted(word, matcher) ? CENSOR : word));
}
