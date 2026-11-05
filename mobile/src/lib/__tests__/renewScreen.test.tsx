import { act, create, type ReactTestInstance } from 'react-test-renderer';

import Renew from '../../../app/(app)/cars/[id]/renew';

const mockStartRenewal = jest.fn();
const mockPoll = jest.fn();
const mockOpen = jest.fn();

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
jest.mock('expo-router', () => ({
  useLocalSearchParams: () => ({ id: '5' }),
  useRouter: () => ({ dismissTo: jest.fn(), push: jest.fn(), replace: jest.fn() }),
  useFocusEffect: (cb: () => void | (() => void)) => {
    // Run like an effect on mount (React's own useEffect, so cleanup runs on unmount).
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    require('react').useEffect(cb, []);
  },
}));
jest.mock('expo-web-browser', () => ({ openBrowserAsync: (...a: unknown[]) => mockOpen(...a) }));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: ({ children }: { children: unknown }) => children }));
jest.mock('../../api/renewals', () => ({ startRenewal: (...a: unknown[]) => mockStartRenewal(...a) }));
jest.mock('../pollPayment', () => ({ pollPayment: (...a: unknown[]) => mockPoll(...a) }));
jest.mock('../../api/cars', () => ({
  getCar: jest.fn(async () => ({ subscription: { active: true, expires_at: '2028-01-10T10:00:00Z' } })),
}));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const VALID_ID = '10000000146';

function input(tree: ReturnType<typeof create>, label: string): ReactTestInstance {
  return tree.root.findAll((n) => n.props.accessibilityLabel === label && typeof n.props.onChangeText === 'function')[0];
}

async function fillAndSubmit(tree: ReturnType<typeof create>) {
  const set = (label: string, value: string) => act(() => input(tree, label).props.onChangeText(value));
  await set('Ad soyad', 'Ayse Yilmaz');
  await set('Fatura adresi', 'Moda Cd. 1');
  await set('Şehir', 'Istanbul');
  await set('Telefon numarası', '0532 123 45 67');
  await set('T.C. kimlik numarası', VALID_ID);
  await act(async () => tree.root.findByProps({ accessibilityRole: 'checkbox' }).props.onPress());
  await act(async () => {
    tree.root
      .findAll((n) => n.props.accessibilityRole === 'button' && typeof n.props.onPress === 'function')
      .find((n) => n.props.accessibilityLabel === 'Ödemeye geç')!
      .props.onPress();
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  process.env.EXPO_PUBLIC_API_URL = 'http://192.168.1.20:8000';
  mockOpen.mockResolvedValue({ type: 'dismiss' });
});

test('the national ID is masked, sent once, and cleared from state afterwards', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1' });
  mockPoll.mockResolvedValue('paid');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  const field = input(tree, 'T.C. kimlik numarası');
  expect(field.props.secureTextEntry).toBe(true);
  expect(field.props.keyboardType).toBe('number-pad');
  expect(field.props.autoComplete).toBe('off');

  await fillAndSubmit(tree);

  expect(mockStartRenewal).toHaveBeenCalledTimes(1);
  expect(mockStartRenewal.mock.calls[0][1].billing_national_id).toBe(VALID_ID);
  expect(mockOpen).toHaveBeenCalledWith('http://192.168.1.20:8000/fake-gateway/checkout/W1');
  // Success came from the backend poll, and the screen shows the new expiry from the server.
  expect(mockPoll).toHaveBeenCalledWith(9, expect.objectContaining({ intervalMs: 3000 }));
  const text = tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');
  expect(text).toContain('Ödeme alındı');
  expect(text).toContain('2028');
});

test('an invalid national ID is refused before any request', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await act(async () => input(tree, 'T.C. kimlik numarası').props.onChangeText('12345678901'));
  await act(async () =>
    tree.root
      .findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Ödemeye geç')[0]
      .props.onPress(),
  );
  expect(mockStartRenewal).not.toHaveBeenCalled();
});

test('an unsafe checkout address is never opened and the owner can check the payment', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://evil.example.com/pay' });
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(mockOpen).not.toHaveBeenCalled();
  expect(mockPoll).not.toHaveBeenCalled();
});

test('a pending result says the payment is being confirmed and offers another check, never failure', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1' });
  mockPoll.mockResolvedValue('pending');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  const text = tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');
  expect(text).toContain('Ödemen doğrulanıyor');
  expect(text).toContain('Tekrar kontrol et');
  expect(text).not.toContain('tamamlanmadı');
});

test('after a refused request the national ID field is empty again and the server message shows', async () => {
  const { ApiError } = jest.requireActual('../../api/errors');
  mockStartRenewal.mockRejectedValue(new ApiError(422, 'validation_failed', 'x', { fields: { billing_name: ['Ad soyad gerekli.'] } }));
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(input(tree, 'T.C. kimlik numarası').props.value).toBe('');
  expect(tree.root.findAll((n) => n.props.children === 'Ad soyad gerekli.').length).toBeGreaterThan(0);
  expect(mockOpen).not.toHaveBeenCalled();
});
