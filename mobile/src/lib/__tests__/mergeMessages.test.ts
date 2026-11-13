import { mergeMessages, type Message } from '../mergeMessages';

const msg = (id: number, extra: Partial<Message> = {}): Message => ({
  id,
  thread_id: 1,
  sender: 'visitor',
  body: `m${id}`,
  read: false,
  reported: false,
  created_at: '2026-11-05T10:00:00+00:00',
  ...extra,
});

test('merges without duplicates when polls overlap', () => {
  const merged = mergeMessages([msg(1), msg(2)], [msg(2), msg(3)]);
  expect(merged.map((m) => m.id)).toEqual([1, 2, 3]);
});

test('keeps order when messages arrive out of order', () => {
  const merged = mergeMessages([msg(5)], [msg(7), msg(3), msg(6)]);
  expect(merged.map((m) => m.id)).toEqual([3, 5, 6, 7]);
});

test('leaves the list unchanged when nothing is new', () => {
  const current = [msg(1), msg(2)];
  expect(mergeMessages(current, [msg(2), msg(1)])).toBe(current);
  expect(mergeMessages(current, [])).toBe(current);
});

test('takes the newer copy of a message already held (reported flag)', () => {
  const merged = mergeMessages([msg(1)], [msg(1, { reported: true })]);
  expect(merged).toHaveLength(1);
  expect(merged[0].reported).toBe(true);
});
