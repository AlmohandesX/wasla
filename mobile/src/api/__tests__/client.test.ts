import { ApiError } from '../errors';
import { apiRequest, setExpiredHandler, setSessionHandlers } from '../client';
import { resolveApiBaseUrl } from '../config';
import { clearToken, getToken, setToken } from '../../auth/token';

const mockStore = new Map<string, string>();
jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async (k: string) => mockStore.get(k) ?? null),
  setItemAsync: jest.fn(async (k: string, v: string) => void mockStore.set(k, v)),
  deleteItemAsync: jest.fn(async (k: string) => void mockStore.delete(k)),
}));

const fetchMock = jest.fn();
const onUnauthorized = jest.fn();
const onEmailNotVerified = jest.fn();

function reply(status: number, body?: unknown, headers: Record<string, string> = {}) {
  return {
    status,
    ok: status >= 200 && status < 300,
    headers: { get: (n: string) => headers[n] ?? headers[n.toLowerCase()] ?? null },
    text: async () => (body === undefined ? '' : JSON.stringify(body)),
  };
}

async function failure(promise: Promise<unknown>): Promise<ApiError> {
  try {
    await promise;
  } catch (e) {
    return e as ApiError;
  }
  throw new Error('expected the request to fail');
}

beforeEach(async () => {
  mockStore.clear();
  await clearToken();
  jest.clearAllMocks();
  fetchMock.mockReset();
  process.env.EXPO_PUBLIC_API_URL = 'http://192.168.1.20:8000';
  (global as unknown as { fetch: unknown }).fetch = fetchMock;
  setSessionHandlers({ onUnauthorized, onEmailNotVerified });
});

test('attaches the bearer token from secure storage', async () => {
  await setToken('abc123');
  fetchMock.mockResolvedValue(reply(200, { id: 1 }));

  await apiRequest('me');

  const [url, init] = fetchMock.mock.calls[0];
  expect(url).toBe('http://192.168.1.20:8000/api/v1/me');
  expect(init.headers.Authorization).toBe('Bearer abc123');
  expect(init.headers['Accept-Language']).toBe('tr');
  expect(init.headers.Accept).toBe('application/json');
});

test('sends no Authorization header when signed out', async () => {
  fetchMock.mockResolvedValue(reply(200, {}));
  await apiRequest('auth/login', { method: 'POST', body: { a: 1 } });
  const init = fetchMock.mock.calls[0][1];
  expect(init.headers.Authorization).toBeUndefined();
  expect(init.body).toBe('{"a":1}');
  expect(init.headers['Content-Type']).toBe('application/json');
});

test('maps a 402 to code subscription_expired', async () => {
  await setToken('t');
  fetchMock.mockResolvedValue(reply(402, { code: 'subscription_expired', message: 'x' }));
  const e = await failure(apiRequest('cars/1', { method: 'PATCH', body: {} }));
  expect(e).toBeInstanceOf(ApiError);
  expect(e.status).toBe(402);
  expect(e.code).toBe('subscription_expired');
});

test('maps a 422 to field errors', async () => {
  fetchMock.mockResolvedValue(
    reply(422, {
      message: 'The email has already been taken.',
      errors: { email: ['taken'], password: ['short', 'weak'] },
    }),
  );
  const e = await failure(apiRequest('auth/register', { method: 'POST', body: {}, auth: false }));
  expect(e.status).toBe(422);
  expect(e.code).toBe('validation_failed');
  expect(e.fields).toEqual({ email: ['taken'], password: ['short', 'weak'] });
});

test('signs out and clears the token on a 401', async () => {
  await setToken('old');
  fetchMock.mockResolvedValue(reply(401, { message: 'Unauthenticated.' }));
  const e = await failure(apiRequest('me'));
  expect(e.code).toBe('unauthenticated');
  expect(await getToken()).toBeNull();
  expect(onUnauthorized).toHaveBeenCalledTimes(1);
});

test('several simultaneous 401s sign out once', async () => {
  await setToken('old');
  fetchMock.mockResolvedValue(reply(401, { message: 'Unauthenticated.' }));
  await Promise.all(
    [apiRequest('me'), apiRequest('cars'), apiRequest('chats')].map((p) => p.catch(() => undefined)),
  );
  expect(onUnauthorized).toHaveBeenCalledTimes(1);
});

test('a stale 401 does not sign out a newer session', async () => {
  await setToken('old');
  fetchMock.mockImplementation(async () => {
    await setToken('new'); // the user signed in again while the request was in flight
    return reply(401, { message: 'Unauthenticated.' });
  });
  await apiRequest('me').catch(() => undefined);
  expect(await getToken()).toBe('new');
  expect(onUnauthorized).not.toHaveBeenCalled();
});

test('a bad login (401 invalid_credentials) does not sign out', async () => {
  fetchMock.mockResolvedValue(reply(401, { code: 'invalid_credentials', message: 'x' }));
  const e = await failure(apiRequest('auth/login', { method: 'POST', body: {}, auth: false }));
  expect(e.code).toBe('invalid_credentials');
  expect(onUnauthorized).not.toHaveBeenCalled();
});

test('reports a network failure as code network_error', async () => {
  fetchMock.mockRejectedValue(new TypeError('Network request failed'));
  const e = await failure(apiRequest('me'));
  expect(e.status).toBe(0);
  expect(e.code).toBe('network_error');
});

test('a request that exceeds the timeout is a network_error', async () => {
  fetchMock.mockImplementation(
    (_url: string, init: { signal: AbortSignal }) =>
      new Promise((_resolve, reject) => {
        init.signal.addEventListener('abort', () => reject(new Error('aborted')));
      }),
  );
  const e = await failure(apiRequest('me', { timeoutMs: 20 }));
  expect(e.code).toBe('network_error');
});

test('maps a 403 email_not_verified to its own code and tells the session', async () => {
  await setToken('t');
  fetchMock.mockResolvedValue(reply(403, { code: 'email_not_verified', message: 'x' }));
  const e = await failure(apiRequest('cars'));
  expect(e.status).toBe(403);
  expect(e.code).toBe('email_not_verified');
  expect(onEmailNotVerified).toHaveBeenCalledTimes(1);
  expect(onUnauthorized).not.toHaveBeenCalled();
});

test('maps a lockout 429 with its own code and retry time', async () => {
  fetchMock.mockResolvedValue(
    reply(429, { code: 'too_many_attempts', message: 'x', retry_after: 600 }, { 'Retry-After': '600' }),
  );
  const e = await failure(apiRequest('auth/login', { method: 'POST', body: {}, auth: false }));
  expect(e.code).toBe('too_many_attempts');
  expect(e.retryAfter).toBe(600);
  expect(e.message).toContain('10');
});

test('maps a generic 429 and a server error', async () => {
  fetchMock.mockResolvedValueOnce(
    reply(429, { code: 'too_many_requests', message: 'x' }, { 'Retry-After': '30' }),
  );
  expect((await failure(apiRequest('cars'))).code).toBe('too_many_requests');
  fetchMock.mockResolvedValueOnce({ ...reply(500), text: async () => '<html>oops</html>' });
  expect((await failure(apiRequest('cars'))).code).toBe('server_error');
});

test('returns undefined for a 204', async () => {
  fetchMock.mockResolvedValue(reply(204));
  await expect(apiRequest('auth/logout', { method: 'POST' })).resolves.toBeUndefined();
});

describe('resolveApiBaseUrl', () => {
  test('allows http for localhost and LAN addresses in development only', () => {
    for (const host of ['localhost', '127.0.0.1', '10.0.2.2', '192.168.1.5', '172.20.0.3', 'pc.local']) {
      expect(resolveApiBaseUrl(`http://${host}:8000`, true)).toBe(`http://${host}:8000/api/v1`);
      expect(() => resolveApiBaseUrl(`http://${host}:8000`, false)).toThrow(ApiError);
    }
  });

  test('refuses http to a public host even in development', () => {
    expect(() => resolveApiBaseUrl('http://example.com', true)).toThrow(ApiError);
    expect(() => resolveApiBaseUrl('http://172.32.0.1', true)).toThrow(ApiError);
    expect(() => resolveApiBaseUrl('http://192.168.1.5.evil.com', true)).toThrow(ApiError);
  });

  test('accepts https anywhere and rejects missing or malformed values', () => {
    expect(resolveApiBaseUrl('https://api.wasla.app/', false)).toBe('https://api.wasla.app/api/v1');
    expect(() => resolveApiBaseUrl(undefined, true)).toThrow(ApiError);
    expect(() => resolveApiBaseUrl('ftp://x', true)).toThrow(ApiError);
    expect(() => resolveApiBaseUrl('not a url', true)).toThrow(ApiError);
  });
});

test('a 402 without a code falls back to subscription_expired', async () => {
  await setToken('t');
  fetchMock.mockResolvedValue(reply(402, { message: 'Payment Required' }));
  const e = await failure(apiRequest('cars/1', { method: 'PATCH', body: {} }));
  expect(e.status).toBe(402);
  expect(e.code).toBe('subscription_expired');
});

test('a 403 account_suspended on an authenticated call signs out with the reason', async () => {
  await setToken('t');
  fetchMock.mockResolvedValue(reply(403, { code: 'account_suspended', message: 'x' }));
  const e = await failure(apiRequest('me'));
  expect(e.code).toBe('account_suspended');
  expect(await getToken()).toBeNull();
  expect(onUnauthorized).toHaveBeenCalledWith('account_suspended');
});

test('a 403 account_suspended at login (no token) only reports the error', async () => {
  fetchMock.mockResolvedValue(reply(403, { code: 'account_suspended', message: 'x' }));
  const e = await failure(apiRequest('auth/login', { method: 'POST', auth: false, body: {} }));
  expect(e.code).toBe('account_suspended');
  expect(onUnauthorized).not.toHaveBeenCalled();
});

test('a new token written but not yet persisted is not cleared by a late 401', async () => {
  await setToken('old');
  // Persisting the new token takes a while; the in-memory token must already be the new one.
  const SecureStore = require('expo-secure-store');
  SecureStore.setItemAsync.mockImplementationOnce(
    (k: string, v: string) => new Promise<void>((r) => setTimeout(() => { mockStore.set(k, v); r(); }, 30)),
  );
  fetchMock.mockImplementation(async () => {
    void setToken('new');
    return reply(401, { message: 'Unauthenticated.' });
  });
  await apiRequest('me').catch(() => undefined);
  await new Promise((r) => setTimeout(r, 60));
  expect(await getToken()).toBe('new');
  expect(onUnauthorized).not.toHaveBeenCalled();
});

test('a 2xx that is not JSON is an invalid_response error, but a 204 is fine', async () => {
  fetchMock.mockResolvedValueOnce({ ...reply(200), text: async () => '<html>captive portal</html>' });
  const e = await failure(apiRequest('me'));
  expect(e.code).toBe('invalid_response');
  fetchMock.mockResolvedValueOnce(reply(200));
  expect((await failure(apiRequest('me'))).code).toBe('invalid_response');
  fetchMock.mockResolvedValueOnce(reply(204));
  await expect(apiRequest('auth/logout', { method: 'POST' })).resolves.toBeUndefined();
});

test('a FormData body is sent as is, with no JSON content type, and the token still goes along', async () => {
  await setToken('abc123');
  fetchMock.mockResolvedValue(reply(200, { has_photo: true }));
  const form = new FormData();
  form.append('photo', new Blob(['x'], { type: 'image/jpeg' }), 'car.jpg');

  await apiRequest('cars/1/photo', { method: 'POST', body: form });

  const [, init] = fetchMock.mock.calls[0];
  expect(init.body).toBe(form);
  expect(init.headers['Content-Type']).toBeUndefined();
  expect(init.headers.Authorization).toBe('Bearer abc123');
});

test('a JSON body still gets the JSON content type', async () => {
  fetchMock.mockResolvedValue(reply(200, {}));
  await apiRequest('cars/1/stealth', { method: 'PUT', body: { enabled: true } });
  const [, init] = fetchMock.mock.calls[0];
  expect(init.headers['Content-Type']).toBe('application/json');
  expect(init.body).toBe('{"enabled":true}');
});

test('an upload that gets 401 signs the session out, and one that times out is a network_error', async () => {
  await setToken('abc123');
  const form = new FormData();
  form.append('photo', new Blob(['x']), 'car.jpg');

  fetchMock.mockResolvedValue(reply(401, { message: 'Unauthenticated.' }));
  const unauthorized = await failure(apiRequest('cars/1/photo', { method: 'POST', body: form }));
  expect(unauthorized.status).toBe(401);
  expect(onUnauthorized).toHaveBeenCalledTimes(1);

  fetchMock.mockImplementation(
    (_url: string, init: { signal: AbortSignal }) =>
      new Promise((_resolve, reject) => {
        init.signal.addEventListener('abort', () => reject(new Error('aborted')));
      }),
  );
  const timedOut = await failure(apiRequest('cars/1/photo', { method: 'POST', body: form, timeoutMs: 20 }));
  expect(timedOut.code).toBe('network_error');
});

describe('subscription_expired', () => {
  const onExpired = jest.fn();
  beforeEach(() => {
    onExpired.mockReset();
    setExpiredHandler(onExpired);
  });
  afterEach(() => setExpiredHandler(null));

  test('a 402 under cars/{id} reports that car only', async () => {
    await setToken('abc');
    fetchMock.mockResolvedValue(reply(402, { code: 'subscription_expired', message: 'x' }));
    const error = await failure(apiRequest('cars/42/status', { method: 'PUT', body: {} }));
    expect(error.code).toBe('subscription_expired');
    expect(onExpired).toHaveBeenCalledWith(42);
    expect(onExpired).toHaveBeenCalledTimes(1);
  });

  test('a 402 on a path without a car id reports nothing', async () => {
    await setToken('abc');
    fetchMock.mockResolvedValue(reply(402, { code: 'subscription_expired', message: 'x' }));
    await failure(apiRequest('chats/9/messages'));
    expect(onExpired).not.toHaveBeenCalled();
  });
});
