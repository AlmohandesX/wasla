import { act, create, type ReactTestInstance } from 'react-test-renderer';

import { RemoveCar } from '../RemoveCar';
import { ApiError } from '../../api/errors';
import type { Car } from '../../api/cars';
import { t } from '../../i18n';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
const mockDeleteCar = jest.fn();
jest.mock('../../api/cars', () => ({
  deleteCar: (...a: unknown[]) => mockDeleteCar(...a),
}));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const CAR: Car = {
  id: 9,
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

const onRemoved = jest.fn();
const onReleaseFirst = jest.fn();

function render(car: Car) {
  let tree!: ReturnType<typeof create>;
  act(() => {
    tree = create(<RemoveCar car={car} onRemoved={onRemoved} onReleaseFirst={onReleaseFirst} />);
  });
  return tree;
}

function texts(root: ReactTestInstance): string {
  return root
    .findAll((n) => (n.type as unknown) === 'Text')
    .map((n) => n.children.join(''))
    .join('|');
}

const byLabel = (root: ReactTestInstance, label: string) =>
  root.find((n) => n.props.accessibilityLabel === label && typeof n.props.onPress === 'function');

const press = async (node: ReactTestInstance) => {
  await act(async () => {
    node.props.onPress();
  });
};

beforeEach(() => jest.clearAllMocks());

test('asks for confirmation that says what is removed and that the sticker stays claimable with its expiry', async () => {
  const tree = render(CAR);
  await press(byLabel(tree.root, t('removeCar.start')));

  const shown = texts(tree.root);
  expect(shown).toContain(t('removeCar.what'));
  expect(shown).toContain(t('removeCar.sticker'));
  expect(mockDeleteCar).not.toHaveBeenCalled();

  const submit = byLabel(tree.root, t('removeCar.submit'));
  expect(submit.props.disabled).toBe(true);

  await press(tree.root.find((n) => n.props.accessibilityRole === 'checkbox' && typeof n.props.onPress === 'function'));
  mockDeleteCar.mockResolvedValue(undefined);
  await press(byLabel(tree.root, t('removeCar.submit')));

  expect(mockDeleteCar).toHaveBeenCalledWith(9);
  expect(onRemoved).toHaveBeenCalled();
});

test('does not mention a sticker for a car with no tag', async () => {
  const tree = render({ ...CAR, linked: false, subscription: { active: false, expires_at: null } });
  await press(byLabel(tree.root, t('removeCar.start')));

  expect(texts(tree.root)).toContain(t('removeCar.what'));
  expect(texts(tree.root)).not.toContain(t('removeCar.sticker'));
});

test('an expired linked car must release its sticker first, as the server requires', async () => {
  const tree = render({ ...CAR, subscription: { active: false, expires_at: '2026-01-01T10:00:00Z' } });

  expect(texts(tree.root)).toContain(t('removeCar.releaseFirst'));
  expect(tree.root.findAll((n) => n.props.accessibilityLabel === t('removeCar.start'))).toHaveLength(0);
  await press(byLabel(tree.root, t('removeCar.release')));
  expect(onReleaseFirst).toHaveBeenCalled();
});

test('shows the error and keeps the car when the delete fails', async () => {
  const tree = render(CAR);
  await press(byLabel(tree.root, t('removeCar.start')));
  await press(tree.root.find((n) => n.props.accessibilityRole === 'checkbox' && typeof n.props.onPress === 'function'));
  mockDeleteCar.mockRejectedValue(new ApiError(0, 'network_error', 'offline'));
  await press(byLabel(tree.root, t('removeCar.submit')));

  expect(texts(tree.root)).toContain('offline');
  expect(onRemoved).not.toHaveBeenCalled();
});
