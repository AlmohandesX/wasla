import { act, create } from 'react-test-renderer';

import Cars from '../../../app/(app)/(tabs)/cars';

const mockPush = jest.fn();
let mockUser: { id: number; email: string; name: string } | null = null;

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
jest.mock('@expo/vector-icons/Ionicons', () => () => null);
jest.mock('react-native-safe-area-context', () => ({ SafeAreaView: ({ children }: { children: unknown }) => children }));
jest.mock('expo-router', () => ({
  useRouter: () => ({ push: mockPush, replace: jest.fn() }),
  useFocusEffect: () => undefined,
}));
jest.mock('../../auth/session', () => ({ useSession: () => ({ user: mockUser, signOut: jest.fn() }) }));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const textOf = (tree: ReturnType<typeof create>) =>
  tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');

async function render() {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Cars />);
  });
  return tree;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUser = { id: 1, email: 'a@b.c', name: 'Ayşe Nur Yılmaz' };
});

describe('home screen', () => {
  test('greets the owner by first name', async () => {
    expect(textOf(await render())).toContain('Hello, Ayşe');
  });

  test('says a plain hello when the owner has no name', async () => {
    mockUser = { id: 1, email: 'a@b.c', name: '  ' };
    const text = textOf(await render());
    expect(text).toContain('Hello');
    expect(text).not.toContain('Hello,');
  });

  test('the floating scan button opens the scanner', async () => {
    const tree = await render();
    const fab = tree.root.findAll(
      (n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Scan a tag to add a car',
    )[0];
    expect(fab).toBeDefined();
    expect(textOf(tree)).toContain('Add car');
    act(() => fab.props.onPress());
    expect(mockPush).toHaveBeenCalledWith('/cars/scan');
  });

  test('has no Sign out, Chats or Account buttons (they live in the tab bar and Account tab)', async () => {
    const labels = (await render()).root
      .findAll((n) => n.props.accessibilityRole === 'button')
      .map((n) => String(n.props.accessibilityLabel ?? ''));
    expect(labels).not.toContain('Sign out');
    expect(labels).not.toContain('Chats');
    expect(labels).not.toContain('Account');
  });

  test('has no language switch (it lives in the Account tab)', async () => {
    const tree = await render();
    expect(tree.root.findAll((n) => n.props.accessibilityRole === 'radiogroup')).toHaveLength(0);
    expect(textOf(tree)).not.toContain('Türkçe');
  });
});
