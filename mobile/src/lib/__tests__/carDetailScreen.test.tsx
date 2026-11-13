import { act, create } from 'react-test-renderer';

import CarDetail from '../../../app/(app)/cars/[id]/index';
import Settings from '../../../app/(app)/cars/[id]/settings';
import type { Car } from '../../api/cars';

const mockPush = jest.fn();
let mockCar: Car;

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
jest.mock('react-native-safe-area-context', () => ({
  SafeAreaView: ({ children }: { children: unknown }) => children,
  useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 0, left: 0 }),
}));
jest.mock('@expo/vector-icons/Ionicons', () => () => null);
jest.mock('expo-router', () => ({
  useLocalSearchParams: () => ({ id: '7' }),
  useRouter: () => ({ push: mockPush, replace: jest.fn(), dismissTo: jest.fn() }),
}));
jest.mock('../../auth/token', () => ({ getToken: jest.fn(async () => null) }));
jest.mock('../../cars/useCar', () => ({ useCar: () => ({ car: mockCar, setCar: jest.fn(), error: null, loading: false }) }));
jest.mock('../../cars/useServerSwitch', () => ({
  useStealth: () => ({ pendingValue: null, error: null, toggle: jest.fn() }),
  useChatsPaused: () => ({ pendingValue: null, error: null, toggle: jest.fn() }),
}));
jest.mock('../../api/cars', () => ({
  ...jest.requireActual('../../api/cars'),
  listContacts: jest.fn(async () => []),
}));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const CAR: Car = {
  id: 7,
  model: 'Renault Clio',
  plate: '34 ABC 123',
  default_message: null,
  current_message: null,
  status: null,
  stealth_enabled: false,
  chats_paused: false,
  linked: true,
  serial_number: 'W001-000123',
  subscription: { active: true, expires_at: '2027-11-05T10:00:00Z' },
};

const buttons = (tree: ReturnType<typeof create>) =>
  [...new Set(tree.root.findAll((n) => n.props.accessibilityRole === 'button').map((n) => n.props.accessibilityLabel as string))];

beforeEach(() => {
  jest.clearAllMocks();
  mockCar = CAR;
});

test('the car screen puts its actions in a bottom bar and leaves the car, serial and stealth above it', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<CarDetail />);
  });

  const texts = tree.root.findAll((n) => typeof n.props.children === 'string').map((n) => n.props.children);
  expect(texts).toEqual(expect.arrayContaining(['Renault Clio', 'Tag serial: W001-000123', 'Status', 'Chats', 'Reminders', 'Settings']));
  expect(texts).not.toContain('Transfer the tag');
  expect(tree.root.findAll((n) => n.props.accessibilityRole === 'switch').length).toBeGreaterThan(0);

  const press = async (label: string) => {
    const item = tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === label)[0];
    await act(async () => item.props.onPress());
  };
  await press('Set status');
  await press('Chats');
  await press('Reminders');
  await press('Settings and emergency contacts');
  expect(mockPush.mock.calls.map((c) => c[0])).toEqual([
    { pathname: '/cars/[id]/status', params: { id: '7' } },
    '/chats',
    { pathname: '/cars/[id]/reminders', params: { id: '7' } },
    { pathname: '/cars/[id]/settings', params: { id: '7' } },
  ]);
});

test('Settings offers the tag transfer for a linked car only', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Settings />);
  });
  expect(buttons(tree)).toContain('Transfer the tag');
  const transfer = tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Transfer the tag')[0];
  await act(async () => transfer.props.onPress());
  expect(mockPush).toHaveBeenCalledWith({ pathname: '/cars/[id]/transfer', params: { id: '7' } });

  mockCar = { ...CAR, linked: false, serial_number: null, subscription: { active: false, expires_at: null } };
  await act(async () => {
    tree.update(<Settings />);
  });
  expect(buttons(tree)).not.toContain('Transfer the tag');
});
