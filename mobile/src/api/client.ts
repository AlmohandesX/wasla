import { clearToken, getToken } from '../auth/token';
import { getLocale } from '../i18n';
import { getApiBaseUrl } from './config';
import { errorFromResponse, networkError } from './errors';

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
  body?: unknown;
  /** Attach the bearer token (default true). Login, register and password reset pass false. */
  auth?: boolean;
  timeoutMs?: number;
};

type SessionHandlers = {
  onUnauthorized?: () => void;
  onEmailNotVerified?: () => void;
};

const DEFAULT_TIMEOUT_MS = 15000;

let handlers: SessionHandlers = {};
let signOutInFlight: Promise<void> | null = null;

export function setSessionHandlers(next: SessionHandlers): void {
  handlers = next;
}

/**
 * One sign-out for any number of simultaneous 401s. A 401 that belongs to a token that has already been
 * replaced or cleared (a late response after sign-out or a new login) does nothing.
 */
function handleUnauthorized(sentToken: string): Promise<void> {
  if (!signOutInFlight) {
    signOutInFlight = (async () => {
      if ((await getToken()) !== sentToken) return;
      await clearToken();
      handlers.onUnauthorized?.();
    })().finally(() => {
      signOutInFlight = null;
    });
  }
  return signOutInFlight;
}

export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const baseUrl = getApiBaseUrl();
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
  try {
    json = text ? JSON.parse(text) : undefined;
  } catch {
    json = undefined;
  }

  if (!response.ok) {
    const error = errorFromResponse(response.status, json, response.headers.get('Retry-After'));
    if (error.status === 401 && token && error.code !== 'invalid_credentials') {
      await handleUnauthorized(token);
    } else if (error.code === 'email_not_verified') {
      handlers.onEmailNotVerified?.();
    }
    throw error;
  }

  return json as T;
}
