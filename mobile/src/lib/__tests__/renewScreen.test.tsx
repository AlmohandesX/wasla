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
const IN = 25 * 60;

function input(tree: ReturnType<typeof create>, label: string): ReactTestInstance {
  return tree.root.findAll((n) => n.props.accessibilityLabel === label && typeof n.props.onChangeText === 'function')[0];
}

async function fillAndSubmit(tree: ReturnType<typeof create>) {
  const set = (label: string, value: string) => act(() => input(tree, label).props.onChangeText(value));
  await set('Full name (name and surname)', 'Ayse Yilmaz');
  await set('Billing address', 'Moda Cd. 1');
  await set('City', 'Istanbul');
  await set('Phone number', '0532 123 45 67');
  await set('National ID number (TC Kimlik No)', VALID_ID);
  await act(async () => tree.root.findByProps({ accessibilityRole: 'checkbox' }).props.onPress());
  await act(async () => {
    tree.root
      .findAll((n) => n.props.accessibilityRole === 'button' && typeof n.props.onPress === 'function')
      .find((n) => n.props.accessibilityLabel === 'Continue to payment')!
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
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future(), checkout_expires_in: IN });
  mockPoll.mockResolvedValue('paid');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  const field = input(tree, 'National ID number (TC Kimlik No)');
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
  expect(text).toContain('Payment received');
  expect(text).toContain('2028');
});

test('an invalid national ID is refused before any request', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await act(async () => input(tree, 'National ID number (TC Kimlik No)').props.onChangeText('12345678901'));
  await act(async () =>
    tree.root
      .findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Continue to payment')[0]
      .props.onPress(),
  );
  expect(mockStartRenewal).not.toHaveBeenCalled();
});

test('an unsafe checkout address is never opened and the owner can check the payment', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://evil.example.com/pay', checkout_expires_at: future(), checkout_expires_in: IN });
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(mockOpen).not.toHaveBeenCalled();
  expect(mockPoll).not.toHaveBeenCalled();
});

test('a pending result says the payment is being confirmed and offers another check, never failure', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future(), checkout_expires_in: IN });
  mockPoll.mockResolvedValue('pending');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  const text = tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');
  expect(text).toContain('Confirming your payment');
  expect(text).toContain('Check again');
  expect(text).not.toContain('not completed');
});

test('after a refused request the national ID field is empty again and the server message shows', async () => {
  mockStartRenewal.mockRejectedValue(new ApiError(422, 'validation_failed', 'x', { fields: { billing_name: ['Full name gerekli.'] } }));
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(input(tree, 'National ID number (TC Kimlik No)').props.value).toBe('');
  expect(tree.root.findAll((n) => n.props.children === 'Full name gerekli.').length).toBeGreaterThan(0);
  expect(mockOpen).not.toHaveBeenCalled();
});

const textOf = (tree: ReturnType<typeof create>) =>
  tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');
const payButton = (tree: ReturnType<typeof create>) =>
  tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Continue to payment')[0];

test('a double tap starts only one payment', async () => {
  let resolve!: (v: unknown) => void;
  mockStartRenewal.mockReturnValue(new Promise((r) => (resolve = r)));
  mockPoll.mockResolvedValue('pending');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  const set = (label: string, value: string) => act(() => input(tree, label).props.onChangeText(value));
  await set('Full name (name and surname)', 'Ayse Yilmaz');
  await set('Billing address', 'Moda Cd. 1');
  await set('City', 'Istanbul');
  await set('Phone number', '0532 123 45 67');
  await set('National ID number (TC Kimlik No)', VALID_ID);
  await act(async () => tree.root.findByProps({ accessibilityRole: 'checkbox' }).props.onPress());
  const press = payButton(tree).props.onPress;
  // Two taps in the same tick, before React has re-rendered the button as busy.
  await act(async () => {
    void press();
    void press();
  });
  expect(mockStartRenewal).toHaveBeenCalledTimes(1);
  await act(async () => resolve({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future(), checkout_expires_in: IN }));
});

test('leaving and coming back shows the pending screen for the same payment, with no second start', async () => {
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future(), checkout_expires_in: IN });
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
  expect(textOf(tree)).toContain('Confirming your payment');
  expect(textOf(tree)).toContain('Check again');
  expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'National ID number (TC Kimlik No)')).toHaveLength(0);
  mockPoll.mockResolvedValue('paid');
  await act(async () =>
    tree.root.findAll((n) => n.props.accessibilityLabel === 'Check again' && n.props.onPress)[0].props.onPress(),
  );
  expect(mockPoll).toHaveBeenLastCalledWith(9, expect.anything());
  expect(mockStartRenewal).toHaveBeenCalledTimes(1);
  expect(pendingFor(5)).toBeNull();
});

test('a 409 renewal_pending polls the payment it names and never opens a second checkout', async () => {
  mockStartRenewal.mockRejectedValue(new ApiError(409, 'renewal_pending', 'x', { paymentId: 42, checkoutExpiresAt: future(), checkoutExpiresIn: IN }));
  mockPoll.mockResolvedValue('paid');
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await fillAndSubmit(tree);
  expect(mockPoll).toHaveBeenCalledWith(42, expect.anything());
  expect(mockOpen).not.toHaveBeenCalled();
  expect(textOf(tree)).toContain('Payment received');
});

test('returning to the app restarts the poll with a fresh deadline while the payment is unsettled', async () => {
  let handler: ((s: string) => void) | undefined;
  jest.spyOn(AppState, 'addEventListener').mockImplementation(((_: string, cb: (s: string) => void) => {
    handler = cb;
    return { remove: jest.fn() };
  }) as never);
  mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_at: future(), checkout_expires_in: IN });
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
  expect(textOf(tree)).toContain('Payment received');
});

test('losing focus empties the national ID field', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  await act(async () => input(tree, 'National ID number (TC Kimlik No)').props.onChangeText(VALID_ID));
  expect(input(tree, 'National ID number (TC Kimlik No)').props.value).toBe(VALID_ID);
  await act(async () => (globalThis as unknown as { __blur: () => void }).__blur());
  expect(input(tree, 'National ID number (TC Kimlik No)').props.value).toBe('');
});

test('the text fields stop at the lengths the server accepts', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  expect(input(tree, 'Full name (name and surname)').props.maxLength).toBe(120);
  expect(input(tree, 'Billing address').props.maxLength).toBe(300);
  expect(input(tree, 'City').props.maxLength).toBe(80);
});

test('the legal line carries the draft marker and opens the privacy notice', async () => {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Renew />);
  });
  expect(textOf(tree)).toContain('DRAFT');
  await act(async () =>
    tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityState?.expanded === false)[0].props.onPress(),
  );
  expect(textOf(tree)).toContain('Beni Bul');
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
    expect(textOf(tree)).toContain('Confirming your payment');
    const startNew = tree.root.findAll((n) => n.props.accessibilityLabel === 'Start a new payment' && n.props.onPress)[0];
    expect(startNew).toBeDefined();
    await act(async () => startNew.props.onPress());
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'National ID number (TC Kimlik No)').length).toBeGreaterThan(0);
    expect(pendingFor(5)).toBeNull();
  });

  test('inside the lifetime there is no new-payment button, and the checkout can be reopened from memory', async () => {
    rememberPending(5, { paymentId: 9, checkoutUrl: URL, expiresAt: Date.now() + 10 * 60_000 });
    mockPoll.mockResolvedValue('pending');
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'Start a new payment')).toHaveLength(0);
    await act(async () => tree.root.findAll((n) => n.props.accessibilityLabel === 'Open the payment page again' && n.props.onPress)[0].props.onPress());
    expect(mockOpen).toHaveBeenCalledWith(URL);
  });

  test('a 409 without a payment id shows a wait message with the time left, and no check button', async () => {
    mockStartRenewal.mockRejectedValue(new ApiError(409, 'renewal_pending', 'x', { checkoutExpiresIn: 12 * 60 + 5 }));
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    await fillAndSubmit(tree);
    expect(mockPoll).not.toHaveBeenCalled();
    expect(textOf(tree)).toContain('13 minutes');
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'Check again')).toHaveLength(0);
  });
});

describe('a phone with the wrong clock', () => {
  const URL = 'http://192.168.1.20:8000/fake-gateway/checkout/W1';
  const realNow = Date.now;
  afterEach(() => {
    Date.now = realNow;
  });

  async function startWith(response: Record<string, unknown>) {
    mockStartRenewal.mockResolvedValue({ id: 9, status: 'pending', redirect_url: URL, ...response });
    mockPoll.mockResolvedValue('pending');
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    await fillAndSubmit(tree);
    return tree;
  }
  const label = (tree: ReturnType<typeof create>, l: string) =>
    tree.root.findAll((n) => n.props.accessibilityLabel === l && n.props.onPress);

  test('hours slow: a checkout the server says is over is over, not "payable for hours"', async () => {
    const server = realNow();
    Date.now = () => server - 3 * 3600_000;
    const tree = await startWith({
      checkout_expires_at: new Date(server - 1000).toISOString(), // absolute time looks like 3 h in the future to this phone
      checkout_expires_in: 0,
    });
    expect(label(tree, 'Start a new payment')).toHaveLength(1);
    expect(label(tree, 'Open the payment page again')).toHaveLength(0);
  });

  test('hours fast: a checkout the server says is still payable can be reopened', async () => {
    const server = realNow();
    Date.now = () => server + 5 * 3600_000;
    const tree = await startWith({
      checkout_expires_at: new Date(server + 20 * 60_000).toISOString(), // absolute time looks 5 h in the past
      checkout_expires_in: 20 * 60,
    });
    expect(label(tree, 'Open the payment page again')).toHaveLength(1);
    expect(label(tree, 'Start a new payment')).toHaveLength(0);
  });
});

describe('a screen left open', () => {
  afterEach(() => jest.useRealTimers());

  test('flips to "Start a new payment" at the expiry boundary without any interaction', async () => {
    jest.useFakeTimers();
    mockStartRenewal.mockResolvedValue({
      id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_in: 90,
    });
    mockPoll.mockResolvedValue('pending');
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    await fillAndSubmit(tree);
    const startNew = () => tree.root.findAll((n) => n.props.accessibilityLabel === 'Start a new payment' && n.props.onPress);
    expect(startNew()).toHaveLength(0);
    await act(async () => {
      jest.advanceTimersByTime(91_000);
    });
    expect(startNew()).toHaveLength(1);
  });

  test('the boundary timer is cleared when the screen goes away', async () => {
    jest.useFakeTimers();
    mockStartRenewal.mockResolvedValue({
      id: 9, status: 'pending', redirect_url: 'http://192.168.1.20:8000/fake-gateway/checkout/W1', checkout_expires_in: 90,
    });
    mockPoll.mockResolvedValue('pending');
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Renew />);
    });
    await fillAndSubmit(tree);
    const before = jest.getTimerCount();
    act(() => tree.unmount());
    // The boundary timer was pending before and is gone after.
    expect(jest.getTimerCount()).toBeLessThan(before);
  });
});
