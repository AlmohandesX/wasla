import en from '../../i18n/en.json';
import tr from '../../i18n/tr.json';
import { MAX_STATUS_LENGTH } from '../../api/cars';
import { STATUS_PRESETS } from '../statusPresets';

test('every preset has a text in both languages that fits the status limit', () => {
  expect(STATUS_PRESETS.length).toBeGreaterThanOrEqual(4);
  const dictionaries = [tr, en] as Record<string, string>[];
  for (const key of STATUS_PRESETS) {
    for (const d of dictionaries) {
      expect(d[key]).toBeTruthy();
      expect(d[key].length).toBeLessThanOrEqual(MAX_STATUS_LENGTH);
    }
  }
});
