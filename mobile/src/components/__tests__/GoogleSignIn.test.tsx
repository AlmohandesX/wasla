import { act, create, type ReactTestInstance } from 'react-test-renderer';

import { GoogleSignInSection } from '../GoogleSignIn';
import { ApiError } from '../../api/errors';
import { t } from '../../i18n';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));

let mockAvailable = true;
const mockGetToken = jest.fn();
jest.mock('../../auth/google', () => ({
  isGoogleSignInAvailable: () => mockAvailable,
  getGoogleIdToken: (...a: unknown[]) => mockGetToken(...a),
}));

const mockSignInWithGoogle = jest.fn();
jest.mock('../../auth/session', () => ({
  useSession: () => ({ signInWithGoogle: (...a: unknown[]) => mockSignInWithGoogle(...a) }),
}));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const onConsentStep = jest.fn();

function render(consentGiven = false) {
  let tree!: ReturnType<typeof create>;
  act(() => {
    tree = create(<GoogleSignInSection consentGiven={consentGiven} onConsentStep={onConsentStep} />);
  });
  return tree;
}

function texts(root: ReactTestInstance): string {
  return root
    .findAll((n) => (n.type as unknown) === 'Text')
    .map((n) => n.children.join(''))
    .join('|');
}

function press(root: ReactTestInstance, label: string) {
  const node = root.find((n) => n.props.accessibilityLabel === label && typeof n.props.onPress === 'function');
  return act(async () => {
    await node.props.onPress();
  });
}

const consentNeeded = new ApiError(422, 'privacy_consent_required', 'x');

beforeEach(() => {
  mockAvailable = true;
  mockGetToken.mockReset();
  mockSignInWithGoogle.mockReset();
  onConsentStep.mockReset();
});

test('shows nothing where Google sign-in is not available (Expo Go, or no client ID)', () => {
  mockAvailable = false;
  expect(render().toJSON()).toBeNull();
});

test('signs a returning Google user straight in', async () => {
  mockGetToken.mockResolvedValue('tok');
  mockSignInWithGoogle.mockResolvedValue(undefined);
  const tree = render();

  await press(tree.root, t('google.continue'));

  expect(mockSignInWithGoogle).toHaveBeenCalledWith('tok', false);
  expect(onConsentStep).not.toHaveBeenCalled();
});

test('closing the Google picker does nothing', async () => {
  mockGetToken.mockResolvedValue(null);
  const tree = render();

  await press(tree.root, t('google.continue'));

  expect(mockSignInWithGoogle).not.toHaveBeenCalled();
  expect(texts(tree.root)).not.toContain(t('errors.unknown'));
});

test('a new Google user must tick the privacy notice; the same token is reused, the picker opens once', async () => {
  mockGetToken.mockResolvedValue('tok');
  mockSignInWithGoogle.mockRejectedValueOnce(consentNeeded).mockResolvedValueOnce(undefined);
  const tree = render();

  await press(tree.root, t('google.continue'));
  expect(onConsentStep).toHaveBeenLastCalledWith(true);
  expect(texts(tree.root)).toContain(t('legal.privacyTitle'));

  // Not ticked: nothing is sent.
  await press(tree.root, t('google.createAccount'));
  expect(texts(tree.root)).toContain(t('register.consentRequired'));
  expect(mockSignInWithGoogle).toHaveBeenCalledTimes(1);

  await press(tree.root, t('register.consent'));
  await press(tree.root, t('google.createAccount'));
  expect(mockSignInWithGoogle).toHaveBeenLastCalledWith('tok', true);
  expect(mockGetToken).toHaveBeenCalledTimes(1);
});

test('cancelling the privacy step goes back to the button', async () => {
  mockGetToken.mockResolvedValue('tok');
  mockSignInWithGoogle.mockRejectedValueOnce(consentNeeded);
  const tree = render();

  await press(tree.root, t('google.continue'));
  await press(tree.root, t('google.cancel'));

  expect(onConsentStep).toHaveBeenLastCalledWith(false);
  expect(texts(tree.root)).toContain(t('google.continue'));
});

test('on the register screen a ticked privacy box is sent with the first request', async () => {
  mockGetToken.mockResolvedValue('tok');
  mockSignInWithGoogle.mockResolvedValue(undefined);
  const tree = render(true);

  await press(tree.root, t('google.continue'));

  expect(mockSignInWithGoogle).toHaveBeenCalledWith('tok', true);
});

test('shows the server\'s reason when Google sign-in is refused', async () => {
  mockGetToken.mockResolvedValue('tok');
  mockSignInWithGoogle.mockRejectedValue(new ApiError(409, 'google_account_conflict', t('errors.google_account_conflict')));
  const tree = render();

  await press(tree.root, t('google.continue'));

  expect(texts(tree.root)).toContain(t('errors.google_account_conflict'));
  expect(onConsentStep).toHaveBeenLastCalledWith(false);
});
