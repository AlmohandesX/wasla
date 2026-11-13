import en from '../../i18n/en.json';
import tr from '../../i18n/tr.json';
import { MAX_STATUS_LENGTH } from '../../api/cars';
import { presetFor, STATUS_PRESETS } from '../statusPresets';

const dictionaries = { tr, en } as Record<string, Record<string, string>>;

test('every preset has a text in both languages that fits the status limit', () => {
  expect(STATUS_PRESETS.length).toBeGreaterThanOrEqual(4);
  for (const { label } of STATUS_PRESETS) {
    for (const d of Object.values(dictionaries)) {
      expect(d[label]).toBeTruthy();
      expect(d[label].length).toBeLessThanOrEqual(MAX_STATUS_LENGTH);
    }
  }
});

test('the preset keys are the ones the server knows', () => {
  expect(STATUS_PRESETS.map((p) => p.key)).toEqual(['back_soon', 'loading', 'at_pharmacy', 'call_me']);
});

test('a note sent exactly as a preset reads is recognised as that preset, in either language', () => {
  for (const [, d] of Object.entries(dictionaries)) {
    const translate = (key: string) => d[key];
    expect(presetFor(d['status.p3'], translate)).toBe('at_pharmacy');
    expect(presetFor(`  ${d['status.p1']}  `, translate)).toBe('back_soon');
    // An edited preset is the owner's own words, sent as typed.
    expect(presetFor(`${d['status.p3']} Back soon`, translate)).toBeUndefined();
  }
});
