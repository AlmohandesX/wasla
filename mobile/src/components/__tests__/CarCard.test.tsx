import { act, create, type ReactTestInstance } from 'react-test-renderer';

import { CarCard } from '../CarCard';
import type { Car } from '../../api/cars';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const CAR: Car = {
  id: 1,
  model: 'Renault Clio',
  plate: '34 ABC 123',
  default_message: null,
  current_message: null,
  status: null,
  stealth_enabled: false,
  chats_paused: false,
  linked: true,
  subscription: { active: true, expires_at: '2027-11-05T10:00:00Z' },
};

function render(props: Partial<React.ComponentProps<typeof CarCard>> = {}) {
  let tree!: ReturnType<typeof create>;
  act(() => {
    tree = create(<CarCard car={CAR} onOpen={() => {}} onToggleStealth={() => {}} {...props} />);
  });
  return tree;
}

function texts(root: ReactTestInstance): string {
  return root
    .findAll((n) => (n.type as unknown) === 'Text')
    .map((n) => n.children.join(''))
    .join('|');
}

function flatStyle(node: ReactTestInstance): Record<string, unknown> {
  return Object.assign({}, ...[node.props.style].flat(Infinity).filter(Boolean));
}

const card = (tree: ReturnType<typeof create>) => tree.root.findByProps({ testID: 'car-card' });

test('CarCard looks clearly different when stealth is on', () => {
  const off = render();
  const on = render({ car: { ...CAR, stealth_enabled: true } });

  expect(texts(off.root)).not.toContain('Gizli mod açık');
  expect(texts(on.root)).toContain('Gizli mod açık'); // a label, not just a color

  const onStyle = flatStyle(card(on));
  const offStyle = flatStyle(card(off));
  expect(onStyle.backgroundColor).not.toBe(offStyle.backgroundColor);
  expect(onStyle.borderStyle).toBe('dashed');
  expect(offStyle.borderStyle).not.toBe('dashed');
});

test('CarCard never claims stealth is on while the request is still pending', () => {
  const pending = render({ stealthPendingValue: true });
  expect(texts(pending.root)).not.toContain('Gizli mod açık');
  expect(texts(pending.root)).toContain('Güncelleniyor');
  expect(flatStyle(card(pending)).borderStyle).not.toBe('dashed');
});

test('CarCard shows the expired state and does not offer the stealth switch', () => {
  const expired = render({ car: { ...CAR, subscription: { active: false, expires_at: '2026-10-01T10:00:00Z' } } });
  expect(texts(expired.root)).toContain('Etiketin süresi doldu');
  expect(expired.root.findAllByProps({ accessibilityRole: 'switch' })).toHaveLength(0);
});

test('CarCard shows an active status and hides one that has run out', () => {
  const future = new Date(Date.now() + 3600_000).toISOString();
  const past = new Date(Date.now() - 3600_000).toISOString();
  expect(texts(render({ car: { ...CAR, status: { text: 'Back in 10', expires_at: future } } }).root)).toContain('Back in 10');
  expect(texts(render({ car: { ...CAR, status: { text: 'Back in 10', expires_at: past } } }).root)).not.toContain('Back in 10');
});

test('an expired CarCard can be opened, which leads to the renewal hard stop', () => {
  const onOpen = jest.fn();
  const expired = render({ onOpen, car: { ...CAR, subscription: { active: false, expires_at: '2026-10-01T10:00:00Z' } } });
  const open = expired.root.findAllByProps({ accessibilityRole: 'button' })[0];
  expect(open.props.disabled).toBeFalsy();
  act(() => open.props.onPress());
  expect(onOpen).toHaveBeenCalledTimes(1);
});
