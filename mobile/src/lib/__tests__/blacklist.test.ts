import { BLACKLIST, BLACKLISTED_WORDS, censor, containsBlacklisted, normalize } from '../blacklist';

// Stand-in entries, so these tests keep proving the rules after the real lists replace the placeholders.
const SAMPLE = ['darn', 'wtf', 'kötü*', 'سيء', 'heck*'];

describe('blacklist structure', () => {
  test('has a list for Arabic, English and Turkish, all flattened into one array', () => {
    expect(Object.keys(BLACKLIST).sort()).toEqual(['ar', 'en', 'tr']);
    expect(BLACKLISTED_WORDS).toEqual([...BLACKLIST.ar, ...BLACKLIST.en, ...BLACKLIST.tr]);
  });

  test('censors the placeholder entries', () => {
    expect(censor('a badword_en and badword_tr and badword_ar')).toBe('a *** and *** and ***');
    expect(censor('badstem_trler')).toBe('***');
  });
});

describe('censor', () => {
  test('replaces each blacklisted word with *** and leaves the rest untouched', () => {
    expect(censor('Well darn, that is  WTF!', SAMPLE)).toBe('Well ***, that is  ***!');
    expect(censor('Nothing to see here.', SAMPLE)).toBe('Nothing to see here.');
  });

  test('matches whole words only, so innocent words containing one survive', () => {
    expect(censor('darning socks', SAMPLE)).toBe('darning socks');
  });

  test('a trailing * entry also catches suffixed forms (Turkish)', () => {
    expect(censor('çok kötü, kötüler, KÖTÜSÜN', SAMPLE)).toBe('çok ***, ***, ***');
    expect(censor('heckin heck', SAMPLE)).toBe('*** ***');
  });

  test('matches Arabic written with tashkeel, tatweel or a different alef', () => {
    expect(censor('هذا سَيِّء جدا', SAMPLE)).toBe('هذا *** جدا');
    expect(censor('هذا سـيـء', SAMPLE)).toBe('هذا ***');
    expect(normalize('أإآ')).toBe('ااا');
  });

  test('treats the Turkish dotted capital İ as i', () => {
    expect(normalize('İyi')).toBe('iyi');
  });

  test('is idempotent, so a retried censored message is unchanged', () => {
    const once = censor('darn it', SAMPLE);
    expect(censor(once, SAMPLE)).toBe(once);
  });
});

describe('containsBlacklisted', () => {
  test('reports whether any blacklisted word is present', () => {
    expect(containsBlacklisted('oh darn', SAMPLE)).toBe(true);
    expect(containsBlacklisted('oh dear', SAMPLE)).toBe(false);
  });
});
