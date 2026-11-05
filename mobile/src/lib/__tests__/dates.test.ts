import { formatDueDate, parseDateInput, todayInIstanbul } from '../dates';

test('parses day.month.year and iso input into an iso date', () => {
  expect(parseDateInput('05.03.2027')).toBe('2027-03-05');
  expect(parseDateInput(' 5/3/2027 ')).toBe('2027-03-05');
  expect(parseDateInput('2027-03-05')).toBe('2027-03-05');
});

test('rejects dates that do not exist or are malformed', () => {
  expect(parseDateInput('31.02.2027')).toBeNull();
  expect(parseDateInput('2027-13-01')).toBeNull();
  expect(parseDateInput('soon')).toBeNull();
  expect(parseDateInput('')).toBeNull();
});

test('formats a stored date as day.month.year without shifting zones', () => {
  expect(formatDueDate('2027-03-05')).toBe('05.03.2027');
});

test('today in Istanbul is a valid iso date', () => {
  expect(todayInIstanbul(new Date('2026-11-05T22:30:00Z'))).toBe('2026-11-06');
  expect(todayInIstanbul(new Date('2026-11-05T20:30:00Z'))).toBe('2026-11-05');
});
