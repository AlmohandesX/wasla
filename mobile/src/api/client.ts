import { clearToken, getToken, tokenGeneration } from '../auth/token';
import { getLocale } from '../i18n';
import { getApiBaseUrl } from './config';
import { ApiError, errorFromResponse, messageFor, networkError } from './errors';

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  /** Attach the bearer token (default true). Login, register and password reset pass false. */
  auth?: boolean;
  timeoutMs?: number;
};

type SessionHandlers = {
  /** The session ended on the server's say-so: the token was rejected, or the account is suspended. */
  onUnauthorized?: (reason: 'unauthenticated' | 'account_suspended') => void;
  onEmailNotVerified?: () => void;
};

const DEFAULT_TIMEOUT_MS = 15000;

let handlers: SessionHandlers = {};
let signOutInFlight: Promise<void> | null = null;

export function setSessionHandlers(next: SessionHandlers): void {
  handlers = next;
}

/**
 * One sign-out for any number of simultaneous 401s. A response that belongs to a token that has since been
 * replaced or cleared (a late answer after sign-out or a new login) does nothing. The check and the clear
 * run with no await between them, so a sign-in cannot slip in between.
 */
function handleUnauthorized(generationSent: number, reason: 'unauthenticated' | 'account_suspended'): Promise<void> {
  if (!signOutInFlight) {
    signOutInFlight = (async () => {
      if (tokenGeneration() !== generationSent) return;
      const cleared = clearToken();
      handlers.onUnauthorized?.(reason);
      await cleared.catch(() => undefined);
    })().finally(() => {
      signOutInFlight = null;
    });
  }
  return signOutInFlight;
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const baseUrl = getApiBaseUrl();
  const generationSent = tokenGeneration();
  const token = options.auth === false ? null : await getToken();

  const headers: Record<string, string> = {
    Accept: 'application/json',
    'Accept-Language': getLocale(),
  };
  if (options.body !== undefined) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = `Bearer ${token}`;

  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), options.timeoutMs ?? DEFAULT_TIMEOUT_MS);

  let response: Response;
  let text: string;
  try {
    response = await fetch(`${baseUrl}/${path.replace(/^\/+/, '')}`, {
      method: options.method ?? 'GET',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
      signal: controller.signal,
    });
    text = await response.text();
  } catch {
    throw networkError(); // offline, DNS, refused, or timed out
  } finally {
    clearTimeout(timer);
  }

  let json: unknown;
  let parsed = true;
  try {
    json = text ? JSON.parse(text) : undefined;
  } catch {
    parsed = false;
  }

  if (!response.ok) {
    const error = errorFromResponse(response.status, json, response.headers.get('Retry-After'));
    if (token && error.status === 401 && error.code !== 'invalid_credentials') {
      await handleUnauthorized(generationSent, 'unauthenticated');
    } else if (token && error.code === 'account_suspended') {
      await handleUnauthorized(generationSent, 'account_suspended');
    } else if (error.code === 'email_not_verified') {
      handlers.onEmailNotVerified?.();
    }
    throw error;
  }

  // A success the app cannot read (a captive portal page, a proxy error page, an empty body) is not a success.
  if (response.status !== 204 && (!parsed || json === undefined)) {
    throw new ApiError(response.status, 'invalid_response', messageFor('invalid_response'));
  }

  return json as T;
}
