import { act, create } from 'react-test-renderer';

import ChatThread from '../../../app/(app)/chats/[id]';
import Account from '../../../app/(app)/(tabs)/account';
import AppLayout from '../../../app/(app)/_layout';
import { ApiError } from '../../api/errors';
import { setExpiredHandler } from '../../api/client';

const mockPush = jest.fn();
const mockReplace = jest.fn();
let mockPathname = '/cars/3/status';
const mockDeleteAccount = jest.fn();
let mockThread: Record<string, unknown> = {};

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: ({ children }: { children: unknown }) => children }));
jest.mock('expo-router', () => {
  const Stack = Object.assign(({ children }: { children: unknown }) => children, { Screen: () => null });
  return {
    Stack,
    usePathname: () => mockPathname,
    useLocalSearchParams: () => ({ id: '7' }),
    useRouter: () => ({ push: mockPush, replace: mockReplace, dismissTo: jest.fn() }),
    useFocusEffect: () => undefined,
  };
});
jest.mock('../../auth/session', () => ({
  useSession: () => ({ user: { id: 1, email: 'a@b.c' }, signOut: jest.fn(), deleteAccount: (...a: unknown[]) => mockDeleteAccount(...a) }),
}));
jest.mock('../../push/register', () => ({ enablePush: jest.fn(), listenForChatTaps: () => () => undefined }));
jest.mock('../../chat/useThread', () => ({ useThread: () => mockThread }));
jest.mock('expo-router/react-navigation', () => ({ useHeaderHeight: () => 0 }));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const textOf = (tree: ReturnType<typeof create>) =>
  tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');

beforeEach(() => {
  jest.clearAllMocks();
  setExpiredHandler(null);
});

describe('chat screen', () => {
  const base = { messages: [], pending: [], loaded: true, fatal: null, blocked: false, pollFailing: false, sendError: null, actionError: null, metaFailed: false };

  test('an expired car offers Renew, which opens that car\'s hard stop', async () => {
    mockThread = { ...base, meta: { id: 1, car: { id: 7, model: 'Clio', linked: true, chats_paused: false, subscription_active: false } } };
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<ChatThread />);
    });
    const renew = tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Renew subscription')[0];
    expect(renew).toBeDefined();
    act(() => renew.props.onPress());
    expect(mockPush).toHaveBeenCalledWith({ pathname: '/cars/[id]/expired', params: { id: '7' } });
  });

  test('sends the text as typed, leaving moderation to the server, and keeps it when refused', async () => {
    const send = jest.fn(async () => false); // the server refused it (e.g. 422 message_blocked)
    mockThread = { ...base, send, meta: { id: 1, car: { id: 7, model: 'Clio', linked: true, chats_paused: false, subscription_active: true } } };
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<ChatThread />);
    });
    const input = () => tree.root.findAll((n) => n.props.accessibilityLabel === 'Your message' && typeof n.props.onChangeText === 'function')[0];
    await act(async () => input().props.onChangeText('  you badword_en  '));
    const sendButton = tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Send')[0];
    await act(async () => sendButton.props.onPress());

    expect(send).toHaveBeenCalledWith('you badword_en');
    expect(input().props.value).toBe('  you badword_en  ');
  });

  test('a message a moderator hid shows as removed and cannot be reported', async () => {
    const message = (id: number, extra: object) => ({
      id, thread_id: 1, sender: 'visitor', body: '', read: true, reported: false, created_at: '2026-11-09T10:00:00+00:00', ...extra,
    });
    mockThread = {
      ...base,
      messages: [message(1, { hidden: true }), message(2, { body: 'Your lights are on' })],
      meta: { id: 1, car: { id: 7, model: 'Clio', linked: true, chats_paused: false, subscription_active: true } },
    };
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<ChatThread />);
    });
    const texts = tree.root.findAll((n) => typeof n.props.children === 'string').map((n) => n.props.children);
    expect(texts).toContain('Removed by a moderator');
    const reports = tree.root.findAll((n) => n.props.accessibilityRole === 'button' && /^Report this message/.test(n.props.accessibilityLabel ?? ''));
    expect([...new Set(reports.map((n) => n.props.accessibilityLabel))]).toEqual(['Report this message: Your lights are on']);
  });

  test('an active car shows no Renew button', async () => {
    mockThread = { ...base, meta: { id: 1, car: { id: 7, model: 'Clio', linked: true, chats_paused: false, subscription_active: true } } };
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<ChatThread />);
    });
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'Renew subscription')).toHaveLength(0);
  });
});

describe('account screen', () => {
  test('switches the app language from the Account tab', async () => {
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Account />);
    });
    const option = (label: string) =>
      tree.root.findAll((n) => n.props.accessibilityRole === 'radio' && typeof n.props.onPress === 'function')
        .find((n) => n.findAll((c) => (c.type as unknown) === 'Text' && c.children.join('') === label).length > 0)!;
    expect(textOf(tree)).toContain('Language');

    try {
      await act(async () => option('Türkçe').props.onPress());
      expect(textOf(tree)).toContain('Dil');
      expect(textOf(tree)).toContain('Hesap');
      expect(option('Türkçe').props.accessibilityState).toEqual({ selected: true });
    } finally {
      await act(async () => option('English').props.onPress());
    }
    expect(textOf(tree)).toContain('Account');
  });

  async function openDelete() {
    let tree!: ReturnType<typeof create>;
    await act(async () => {
      tree = create(<Account />);
    });
    await act(async () => tree.root.findAll((n) => n.props.accessibilityLabel === 'Delete my account' && n.props.onPress)[0].props.onPress());
    return tree;
  }
  const confirm = async (tree: ReturnType<typeof create>) => {
    await act(async () =>
      tree.root.findAll((n) => n.props.accessibilityLabel === 'Your password' && typeof n.props.onChangeText === 'function')[0].props.onChangeText('pw'),
    );
    await act(async () =>
      tree.root.findAll((n) => n.props.accessibilityLabel === 'Permanently delete my account' && n.props.onPress)[0].props.onPress(),
    );
  };

  test('shows the warning about erased data and claimable tags', async () => {
    const tree = await openDelete();
    expect(textOf(tree)).toContain('permanently erases');
    expect(textOf(tree)).toContain('free year');
  });

  test('a 429 shows the retry time in minutes', async () => {
    mockDeleteAccount.mockRejectedValue(new ApiError(429, 'too_many_requests', 'x', { retryAfter: 125 }));
    const tree = await openDelete();
    await confirm(tree);
    expect(textOf(tree)).toContain('3 minutes');
  });

  test('a wrong password is shown beside the password field and the field is emptied', async () => {
    mockDeleteAccount.mockRejectedValue(new ApiError(422, 'invalid_password', 'That password is not correct.'));
    const tree = await openDelete();
    await confirm(tree);
    expect(textOf(tree)).toContain('That password is not correct.');
    expect(tree.root.findAll((n) => n.props.accessibilityLabel === 'Your password' && typeof n.props.onChangeText === 'function')[0].props.value).toBe('');
  });
});

describe('app layout expiry routing', () => {
  async function mount() {
    await act(async () => {
      create(<AppLayout />);
    });
  }

  test('a car screen that hits subscription_expired is replaced by that car\'s hard stop', async () => {
    mockPathname = '/cars/3/status';
    // The handler the layout registers is what client.ts calls on a 402 under cars/{id}.
    const client = jest.requireActual('../../api/client') as { setExpiredHandler: typeof setExpiredHandler };
    let captured: ((id: number) => void) | null = null;
    jest.spyOn(client, 'setExpiredHandler').mockImplementation((fn) => {
      captured = fn;
    });
    await mount();
    captured!(3);
    expect(mockReplace).toHaveBeenCalledWith({ pathname: '/cars/[id]/expired', params: { id: '3' } });
  });

  test('from another screen it pushes, and on the hard stop itself it does nothing', async () => {
    const client = jest.requireActual('../../api/client') as { setExpiredHandler: typeof setExpiredHandler };
    let captured: ((id: number) => void) | null = null;
    jest.spyOn(client, 'setExpiredHandler').mockImplementation((fn) => {
      captured = fn;
    });
    mockPathname = '/chats/9';
    await mount();
    captured!(3);
    expect(mockPush).toHaveBeenCalledWith({ pathname: '/cars/[id]/expired', params: { id: '3' } });

    jest.clearAllMocks();
    mockPathname = '/cars/3/expired';
    await mount();
    captured!(3);
    expect(mockPush).not.toHaveBeenCalled();
    expect(mockReplace).not.toHaveBeenCalled();
  });
});
