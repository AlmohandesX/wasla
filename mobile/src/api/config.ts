import { ApiError, messageFor } from './errors';

function configError(): ApiError {
  return new ApiError(0, 'config_error', messageFor('config_error'));
}

/** localhost and private-network addresses: the only places plain http is acceptable. */
export function isLocalHost(host: string): boolean {
  if (host === 'localhost' || host === '[::1]' || host.endsWith('.local')) return true;
  const m = host.match(/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/);
  if (!m) return false;
  const [a, b] = [Number(m[1]), Number(m[2])];
  return a === 127 || a === 10 || (a === 192 && b === 168) || (a === 172 && b >= 16 && b <= 31);
}

/**
 * EXPO_PUBLIC_API_URL is the server origin; the API lives under /api/v1.
 * https is always accepted. Plain http is accepted only when allowLocalHttp() (a development build or a local
 * test build) and only for a localhost or LAN address, so a store build can never send tokens over cleartext.
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

/**
 * Whether plain http to a localhost/LAN address is allowed: in a development build, or in a test build made with
 * EXPO_PUBLIC_ALLOW_LOCAL_HTTP=1 (the EAS "preview" profile, for testing against a server on the local network).
 * The value is inlined at build time, so a store build without it can never be switched to cleartext.
 */
export function allowLocalHttp(): boolean {
  // Must stay a literal property access so Expo can inline it into the bundle.
  return __DEV__ || process.env.EXPO_PUBLIC_ALLOW_LOCAL_HTTP === '1';
}

export function getApiBaseUrl(): string {
  // Must stay a literal property access so Expo can inline it into the bundle.
  return resolveApiBaseUrl(process.env.EXPO_PUBLIC_API_URL, allowLocalHttp());
}

/** EXPO_PUBLIC_TAG_BASE_URL is the printed tag prefix and already ends in /t, so a tag URL is `<base>/<token>`. */
export function getTagBaseUrl(): string {
  // Must stay a literal property access so Expo can inline it into the bundle.
  const raw = process.env.EXPO_PUBLIC_TAG_BASE_URL;
  if (!raw) throw configError();
  return raw;
}
