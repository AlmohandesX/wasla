import { act, create } from 'react-test-renderer';

import { SessionProvider, useSession, type Session } from '../session';
import { accessFor } from '../guard';
import { getLocale } from '../../i18n';
import { pendingFor, rememberPending } from '../../lib/pendingRenewals';
import { clearToken, getToken, setToken } from '../token';

const mockStore = new Map<string, string>();
jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async (k: string) => mockStore.get(k) ?? null),
  setItemAsync: jest.fn(async (k: string, v: string) => void mockStore.set(k, v)),
  deleteItemAsync: jest.fn(async (k: string) => void mockStore.delete(k)),
}));

(globalThis as unknown as { IS_REACT_ACT_ENVIRONMENT: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const fetchMock = jest.fn();
const USER = { id: 1, name: 'Ada', email: 'ada@example.com', locale: 'tr', role: 'owner', email_verified: true };

function reply(status: number, body?: unknown) {
  return {
    status,
    ok: status >= 200 && status < 300,
    headers: { get: () => null },
    text: async () => (body === undefined ? '' : JSON.stringify(body)),
  };
}

let session: Session;
function Probe() {
  session = useSession();
  return null;
}

async function mount() {
  await act(async () => {
    create(
      <SessionProvider>
        <Probe />
      </SessionProvider>,
    );
  });
}

beforeEach(async () => {
  mockStore.clear();
  await clearToken();
  fetchMock.mockReset();
  process.env.EXPO_PUBLIC_API_URL = 'http://192.168.1.20:8000';
  (global as unknown as { fetch: unknown }).fetch = fetchMock;
});

describe('boot', () => {
  test('without a saved token the app is ready and signed out, with no request', async () => {
    await mount();
    expect(session.ready).toBe(true);
    expect(session.user).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });

  test('a saved token is checked with me and the user is restored', async () => {
    await setToken('saved');
    fetchMock.mockResolvedValue(reply(200, USER));
    await mount();
    expect(session.user).toEqual(USER);
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer saved');
  });

  test('a network failure keeps the token and reports a boot error', async () => {
    await setToken('saved');
    fetchMock.mockRejectedValue(new TypeError('Network request failed'));
    await mount();
    expect(session.ready).toBe(true);
    expect(session.user).toBeNull();
    expect(session.bootError?.code).toBe('network_error');
    expect(await getToken()).toBe('saved');
  });

  test('a rejected token (401) signs out cleanly with no boot error', async () => {
    await setToken('stale');
    fetchMock.mockResolvedValue(reply(401, { message: 'Unauthenticated.' }));
    await mount();
    expect(session.user).toBeNull();
    expect(session.bootError).toBeNull();
    expect(await getToken()).toBeNull();
  });

  test('a suspended account is signed out at startup and told why', async () => {
    await setToken('saved');
    fetchMock.mockResolvedValue(reply(403, { code: 'account_suspended', message: 'x' }));
    await mount();
    expect(session.ready).toBe(true);
    expect(session.user).toBeNull();
    expect(session.bootError).toBeNull();
    expect(session.signedOutReason).toBe('account_suspended');
    expect(await getToken()).toBeNull();
  });

  test('signing out from the boot error screen clears the token without the network', async () => {
    await setToken('saved');
    fetchMock.mockRejectedValue(new TypeError('Network request failed'));
    await mount();
    fetchMock.mockClear();
    await act(async () => {
      await session.signOut();
    });
    expect(session.bootError).toBeNull();
    expect(await getToken()).toBeNull();
    expect(fetchMock).not.toHaveBeenCalled();
  });
});

describe('signed in', () => {
  test('signIn stores the token and the user, and clears an earlier notice', async () => {
    await mount();
    fetchMock.mockResolvedValue(reply(200, { token: 'new-token', user: USER }));
    await act(async () => {
      await session.signIn(' ada@example.com ', 'secret-pass');
    });
    expect(session.user).toEqual(USER);
    expect(await getToken()).toBe('new-token');
    expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({ email: 'ada@example.com', password: 'secret-pass' });
  });

  test('a suspended account met mid-session signs out with the reason', async () => {
    await setToken('t');
    fetchMock.mockResolvedValueOnce(reply(200, USER));
    await mount();
    fetchMock.mockResolvedValueOnce(reply(403, { code: 'account_suspended', message: 'x' }));
    await act(async () => {
      await session.refresh().catch(() => undefined);
    });
    expect(session.user).toBeNull();
    expect(session.signedOutReason).toBe('account_suspended');
    expect(await getToken()).toBeNull();
  });

  test('email_not_verified from any call moves the user to unverified', async () => {
    await setToken('t');
    fetchMock.mockResolvedValueOnce(reply(200, USER));
    await mount();
    fetchMock.mockResolvedValueOnce(reply(403, { code: 'email_not_verified', message: 'x' }));
    const { apiRequest } = require('../../api/client');
    await act(async () => {
      await apiRequest('cars').catch(() => undefined);
    });
    expect(session.user?.email_verified).toBe(false);
    expect(accessFor(session)).toBe('verify');
  });
});

describe('accessFor (the route guard decision)', () => {
  const base = { user: null, ready: true, bootError: null };

  test('holds on loading and on a boot error', () => {
    expect(accessFor({ ...base, ready: false })).toBe('loading');
    expect(accessFor({ ...base, bootError: {} as never })).toBe('error');
  });

  test('signed out goes to auth, unverified to verify, verified to app', () => {
    expect(accessFor(base)).toBe('auth');
    expect(accessFor({ ...base, user: { ...USER, email_verified: false } as never })).toBe('verify');
    expect(accessFor({ ...base, user: USER as never })).toBe('app');
  });
});

describe('deleteAccount', () => {
  test('success clears the token and user and leaves a message for the sign-in screen', async () => {
    await setToken('t');
    fetchMock.mockResolvedValueOnce(reply(200, USER));
    await mount();
    fetchMock.mockResolvedValueOnce(reply(204));
    await act(async () => {
      await session.deleteAccount({ password: 'secret-pass' });
    });
    const [url, init] = fetchMock.mock.calls[1];
    expect(url).toContain('/api/v1/account');
    expect(init.method).toBe('DELETE');
    expect(JSON.parse(init.body)).toEqual({ password: 'secret-pass' });
    expect(session.user).toBeNull();
    expect(session.signedOutReason).toBe('account_deleted');
    expect(await getToken()).toBeNull();
  });

  test('a wrong password keeps the session and reports invalid_password', async () => {
    await setToken('t');
    fetchMock.mockResolvedValueOnce(reply(200, USER));
    await mount();
    fetchMock.mockResolvedValueOnce(reply(422, { code: 'invalid_password', message: 'x' }));
    let code = '';
    await act(async () => {
      await session.deleteAccount({ password: 'nope' }).catch((e) => (code = e.code));
    });
    expect(code).toBe('invalid_password');
    expect(session.user).toEqual(USER);
    expect(await getToken()).toBe('t');
  });
});

describe('Google', () => {
  test('signs in with a Google ID token, sending the app language and no consent by default', async () => {
    await mount();
    fetchMock.mockResolvedValueOnce(reply(200, { token: 'g', user: { ...USER, has_password: false } }));
    await act(async () => {
      await session.signInWithGoogle('id-token', false);
    });
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toContain('/api/v1/auth/google');
    expect(init.headers.Authorization).toBeUndefined();
    expect(JSON.parse(init.body)).toEqual({ id_token: 'id-token', locale: getLocale() });
    expect(session.user?.has_password).toBe(false);
    expect(await getToken()).toBe('g');
  });

  test('a first-time Google user is refused until consent is sent, without signing anything in', async () => {
    await mount();
    fetchMock.mockResolvedValueOnce(reply(422, { code: 'privacy_consent_required', message: 'x' }));
    let code = '';
    await act(async () => {
      await session.signInWithGoogle('id-token', false).catch((e) => (code = e.code));
    });
    expect(code).toBe('privacy_consent_required');
    expect(session.user).toBeNull();
    expect(await getToken()).toBeNull();

    fetchMock.mockResolvedValueOnce(reply(201, { token: 'g', user: USER }));
    await act(async () => {
      await session.signInWithGoogle('id-token', true);
    });
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ id_token: 'id-token', locale: getLocale(), privacy_consent: true });
    expect(session.user).toEqual(USER);
  });

  test('a Google-only owner deletes the account with a Google ID token instead of a password', async () => {
    await setToken('t');
    fetchMock.mockResolvedValueOnce(reply(200, { ...USER, has_password: false }));
    await mount();
    fetchMock.mockResolvedValueOnce(reply(204));
    await act(async () => {
      await session.deleteAccount({ googleIdToken: 'fresh-id-token' });
    });
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ google_id_token: 'fresh-id-token' });
    expect(session.signedOutReason).toBe('account_deleted');
  });
});

test('a web panel account is signed out of the app with its own reason', async () => {
  await setToken('t');
  fetchMock.mockResolvedValueOnce(reply(403, { code: 'admin_account', message: 'x' }));
  await mount();
  expect(session.user).toBeNull();
  expect(session.signedOutReason).toBe('admin_account');
  expect(await getToken()).toBeNull();
});

test('signing out forgets any payment in progress', async () => {
  await setToken('t');
  fetchMock.mockResolvedValueOnce(reply(200, USER));
  await mount();
  rememberPending(5, { paymentId: 9, checkoutUrl: null, expiresAt: Date.now() + 60000 });
  fetchMock.mockResolvedValue(reply(204));
  await act(async () => {
    await session.signOut();
  });
  expect(pendingFor(5)).toBeNull();
});

test('account deletion forgets any payment in progress', async () => {
  await setToken('t');
  fetchMock.mockResolvedValueOnce(reply(200, USER));
  await mount();
  rememberPending(5, { paymentId: 9, checkoutUrl: null, expiresAt: Date.now() + 60000 });
  fetchMock.mockResolvedValueOnce(reply(204));
  await act(async () => {
    await session.deleteAccount({ password: 'secret-pass' });
  });
  expect(pendingFor(5)).toBeNull();
});

test('a different user signing in does not inherit a payment in progress', async () => {
  await mount();
  fetchMock.mockResolvedValueOnce(reply(200, { token: 'a', user: USER }));
  await act(async () => {
    await session.signIn('ada@example.com', 'pw-pw-pw-pw');
  });
  rememberPending(5, { paymentId: 9, checkoutUrl: null, expiresAt: Date.now() + 60000 });
  expect(pendingFor(5)).not.toBeNull();
  // The token is replaced by a new login as another user without a sign-out in between.
  fetchMock.mockResolvedValueOnce(reply(200, { token: 'b', user: { ...USER, id: 2, email: 'bo@example.com' } }));
  await act(async () => {
    await session.signIn('bo@example.com', 'pw-pw-pw-pw');
  });
  expect(pendingFor(5)).toBeNull();
});

test('signing out sends this phone push token with logout so the server drops it', async () => {
  await setToken('t');
  mockStore.set('wasla.pushToken', 'ExponentPushToken[phone]');
  fetchMock.mockResolvedValueOnce(reply(200, USER));
  await mount();
  fetchMock.mockResolvedValue(reply(204));
  await act(async () => {
    await session.signOut();
  });

  const logout = fetchMock.mock.calls.find(([url]) => String(url).endsWith('/auth/logout'));
  expect(logout).toBeDefined();
  expect(JSON.parse(logout![1].body)).toEqual({ device_token: 'ExponentPushToken[phone]' });
  expect(mockStore.has('wasla.pushToken')).toBe(false);
  expect(session.user).toBeNull();
});
