import { act, create } from 'react-test-renderer';

import Cars from '../../../app/(app)/cars/index';
import Home from '../../../app/(app)/(tabs)/home';
import Services from '../../../app/(app)/services';

const mockPush = jest.fn();
let mockUser: { id: number; email: string; name: string } | null = null;

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));
jest.mock('@expo/vector-icons/Ionicons', () => () => null);
jest.mock('react-native-safe-area-context', () => ({
  SafeAreaView: ({ children }: { children: unknown }) => children,
  useSafeAreaInsets: () => ({ top: 0, right: 0, bottom: 34, left: 0 }),
}));
jest.mock('expo-router', () => ({
  useRouter: () => ({ push: mockPush, replace: jest.fn() }),
  useFocusEffect: () => undefined,
}));
jest.mock('../../auth/session', () => ({ useSession: () => ({ user: mockUser, signOut: jest.fn() }) }));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const textOf = (tree: ReturnType<typeof create>) =>
  tree.root.findAll((n) => (n.type as unknown) === 'Text').map((n) => n.children.join('')).join('|');

async function render(Screen: () => React.ReactElement = Home) {
  let tree!: ReturnType<typeof create>;
  await act(async () => {
    tree = create(<Screen />);
  });
  return tree;
}

const buttonLabels = (tree: ReturnType<typeof create>) =>
  [...new Set(tree.root.findAll((n) => n.props.accessibilityRole === 'button').map((n) => String(n.props.accessibilityLabel ?? '')))];

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

  test('is a dashboard: a Cars card and a Services card, and no car list', async () => {
    const tree = await render();
    expect(buttonLabels(tree)).toEqual(['Cars', 'Services']);
    expect(tree.root.findAll((n) => n.props.testID === 'car-card')).toHaveLength(0);
    expect(textOf(tree)).toContain('Coming soon');

    const press = (label: string) =>
      act(() => tree.root.findAll((n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === label)[0].props.onPress());
    press('Cars');
    press('Services');
    expect(mockPush.mock.calls).toEqual([['/cars'], ['/services']]);
  });

  test('the services screen says it is coming soon', async () => {
    expect(textOf(await render(Services))).toContain('Coming soon');
  });
});

describe('my cars screen', () => {
  test('the floating scan button opens the scanner and keeps clear of the home indicator', async () => {
    const tree = await render(Cars);
    const fab = tree.root.findAll(
      (n) => n.props.accessibilityRole === 'button' && n.props.accessibilityLabel === 'Scan a tag to add a car',
    )[0];
    expect(fab).toBeDefined();
    expect(textOf(tree)).toContain('Add car');
    act(() => fab.props.onPress());
    expect(mockPush).toHaveBeenCalledWith('/cars/scan');
    const style = fab.props.style({ pressed: false });
    expect(style).toEqual(expect.arrayContaining([{ bottom: 16 + 34 }]));
  });
});

describe('home screen, what stays elsewhere', () => {

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
