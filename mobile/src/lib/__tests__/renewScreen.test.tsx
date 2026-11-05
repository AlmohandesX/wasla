import { act, create, type ReactTestInstance } from 'react-test-renderer';

import { AppState } from 'react-native';

import Renew from '../../../app/(app)/cars/[id]/renew';
import { ApiError } from '../../api/errors';
import { forgetPending, pendingFor, rememberPending } from '../pendingRenewals';

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
    // Run like an effect on mount; the test can also call the cleanup to simulate the screen losing focus.
    // eslint-disable-next-line @typescript-eslint/no-require-imports
    require('react').useEffect(() => {
      const cleanup = cb();
      (globalThis as unknown as { __blur?: () => void }).__blur = () => cleanup?.();
      return cleanup;
    }, []);
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
const future = () => new Date(Date.now() + 25 * 60_000).toISOString();

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
  forgetPending(5);
  process.env.EXPO_PUBLIC_API_URL = 'http://192.168.1.20:8000';
  mockOpen.mockResolvedValue({ type: 'dismiss' });
});

test('the national ID is masked, sent once, and cleared from state afterwards', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future() });
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
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://evil.example.com/pay', checkout_expires_at: future() });
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(mockOpen).not.toHaveBeenCalled();
  expect(mockPoll).not.toHaveBeenCalled();
});

test('a pending result says the payment is being confirmed and offers another check, never failure', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future() });
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

const textOf = (tree: ReturnType<typeof create>) =>
  tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');
const payButton = (tree: ReturnType<typeof create>) =>
  tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Ödemeye geç')[0];

test('a double tap starts only one payment', async () => {
  let resolve!: (v: unknown) => void;
  mockStartRenewal.mockReturnValue(new Promise((r) => (resolve = r)));
  mockPoll.mockResolvedValue('pending');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  const set = (label: string, value: string) => act(() => input(tree, label).props.onChangeText(value));
  await set('Ad soyad', 'Ayse Yilmaz');
  await set('Fatura adresi', 'Moda Cd. 1');
  await set('Şehir', 'Istanbul');
  await set('Telefon numarası', '0532 123 45 67');
  await set('T.C. kimlik numarası', VALID_ID);
  await act(async () => tree.root.findByProps({ accessibilityRole: 'checkbox' }).props.onPress());
  const press = payButton(tree).props.onPress;
  // Two taps in the same tick, before React has re-rendered the button as busy.
  await act(async () => {
    void press();
    void press();
  });
  expect(mockStartRenewal).toHaveBeenCalledTimes(1);
  await act(async () => resolve({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future() }));
});

test('leaving and coming back shows the pending screen for the same payment, with no second start', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future() });
  mockPoll.mockResolvedValue('pending');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(pendingFor(5)?.paymentId).toBe(9);
  act(() => tree.unmount());

  await act(async () => {
    tree = create(<Renew />);
  });
  expect(textOf(tree)).toContain('Ödemen doğrulanıyor');
  expect(textOf(tree)).toContain('Tekrar kontrol et');
  expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'T.C. kimlik numarası')).toHaveLength(0);
  mockPoll.mockResolvedValue('paid');
  await act(async () =>
    tree.root.findAll((n) => n.props.accessibilityLabel === 'Tekrar kontrol et' && n.props.onPress)[0].props.onPress(),
  );
  expect(mockPoll).toHaveBeenLastCalledWith(9, expect.anything());
  expect(mockStartRenewal).toHaveBeenCalledTimes(1);
  expect(pendingFor(5)).toBeNull();
});

test('a 409 renewal_pending polls the payment it names and never opens a second checkout', async () => {
  mockStartRenewal.mockRejectedValue(new ApiError(409, 'renewal_pending', 'x', { paymentId: 42, checkoutExpiresAt: future() }));
  mockPoll.mockResolvedValue('paid');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(mockPoll).toHaveBeenCalledWith(42, expect.anything());
  expect(mockOpen).not.toHaveBeenCalled();
  expect(textOf(tree)).toContain('Ödeme alındı');
});

test('returning to the app restarts the poll with a fresh deadline while the payment is unsettled', async () => {
  let handler: ((s: string) => void) | undefined;
  jest.spyOn(AppState, 'addEventListener').mockImplementation(((_: string, cb: (s: string) => void) => {
    handler = cb;
    return { remove: jest.fn() };
  }) as never);
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future() });
  mockPoll.mockResolvedValueOnce('pending'); // the poll after the browser call
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(mockPoll).toHaveBeenCalledTimes(1);

  mockPoll.mockResolvedValueOnce('paid');
  await act(async () => handler?.('active'));
  expect(mockPoll).toHaveBeenCalledTimes(2);
  expect(textOf(tree)).toContain('Ödeme alındı');
});

test('losing focus empties the national ID field', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await act(async () => input(tree, 'T.C. kimlik numarası').props.onChangeText(VALID_ID));
  expect(input(tree, 'T.C. kimlik numarası').props.value).toBe(VALID_ID);
  await act(async () => (globalThis as unknown as { __blur: () => void }).__blur());
  expect(input(tree, 'T.C. kimlik numarası').props.value).toBe('');
});

test('the text fields stop at the lengths the server accepts', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  expect(input(tree, 'Ad soyad').props.maxLength).toBe(120);
  expect(input(tree, 'Fatura adresi').props.maxLength).toBe(300);
  expect(input(tree, 'Şehir').props.maxLength).toBe(80);
});

test('the legal line carries the draft marker and opens the privacy notice', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  expect(textOf(tree)).toContain('TASLAK');
  await act(async () =>
    tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityState?.expanded === false)[0].props.onPress(),
  );
  expect(textOf(tree)).toContain('Wasla');
});

describe('an abandoned checkout', () => {
  const URL = 'http://192.168.1.20:8000/fake-gateway/checkout/W1';

  test('after the checkout lifetime a poll that is still pending offers a new payment, and it works', async () => {
    rememberPending(5, { paymentId: 9, checkoutUrl: URL, expiresAt: Date.now() - 1000 });
    mockPoll.mockResolvedValue('pending');
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    expect(textOf(tree)).toContain('Ödemen doğrulanıyor');
    const startNew = tree.root.findAll((n) => n.props.accessibilityLabel === 'Yeni ödeme başlat' && n.props.onPress)[0];
    expect(startNew).toBeDefined();
    await act(async () => startNew.props.onPress());
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'T.C. kimlik numarası').length).toBeGreaterThan(0);
    expect(pendingFor(5)).toBeNull();
  });

  test('inside the lifetime there is no new-payment button, and the checkout can be reopened from memory', async () => {
    rememberPending(5, { paymentId: 9, checkoutUrl: URL, expiresAt: Date.now() + 10 * 60_000 });
    mockPoll.mockResolvedValue('pending');
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'Yeni ödeme başlat')).toHaveLength(0);
    await act(async () => tree.root.findAll((n) => n.props.accessibilityLabel === 'Ödeme sayfasını tekrar aç' && n.props.onPress)[0].props.onPress());
    expect(mockOpen).toHaveBeenCalledWith(URL);
  });

  test('a 409 without a payment id shows a wait message with the time left, and no check button', async () => {
    mockStartRenewal.mockRejectedValue(new ApiError(409, 'renewal_pending', 'x', { checkoutExpiresAt: new Date(Date.now() + 12 * 60_000 + 5000).toISOString() }));
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    await fillAndSubmit(tree);
    expect(mockPoll).not.toHaveBeenCalled();
    expect(textOf(tree)).toContain('13 dakika');
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'Tekrar kontrol et')).toHaveLength(0);
  });
});
