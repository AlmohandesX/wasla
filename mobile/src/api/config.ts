import { ApiError, messageFor } from './errors';

function configError(): ApiError {
  return new ApiError(0, 'config_error', messageFor('config_error'));
}

/** localhost and private-network addresses: the only places plain http is acceptable. */
function isLocalHost(host: string): boolean {
  if (host === 'localhost' || host === '[::1]' || host.endsWith('.local')) return true;
  const m = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (!m) return false;
  const [a, b] = [Number(m[1]), Number(m[2])];
  return a === 127 || a === 10 || (a === 192 && b === 168) || (a === 172 && b >= 16 && b <= 31);
}

/**
 * EXPO_PUBLIC_API_URL is the server origin; the API lives under /api/v1.
 * https is always accepted. Plain http is accepted only in a development build and only for a
 * localhost or LAN address, so a release build can never send tokens over cleartext.
 */
export function resolveApiBaseUrl(raw: string | undefined, isDev: boolean): string {
  if (!raw) throw configError();
  let url: URL;
  try {
    url = new URL(raw.trim());
  } catch {
    throw configError();
  }
  const secure = url.protocol === 'https:';
  if (!secure && !(url.protocol === 'http:' && isDev && isLocalHost(url.hostname))) {
    throw configError();
  }
  return `${url.origin}${url.pathname.replace(/\/+$/, '')}/api/v1`;
}

export function getApiBaseUrl(): string {
  // Must stay a literal property access so Expo can inline it into the bundle.
  return resolveApiBaseUrl(process.env.EXPO_PUBLIC_API_URL, __DEV__);
}
