import { isLocalHost } from '../api/config';

/**
 * Whether the hosted checkout address the backend returned may be opened. https always; plain http only in a
 * development build or the local test APK, to the same local host the API itself is configured with (the fake gateway).
 */
export function isAllowedCheckoutUrl(raw: unknown, apiBaseUrl: string, allowHttp: boolean): boolean {
  if (typeof raw !== 'string') return false;
  let url: URL;
  let api: URL;
  try {
    url = new URL(raw);
    api = new URL(apiBaseUrl);
  } catch {
    return false;
  }
  if (url.username || url.password) return false;
  if (url.protocol === 'https:') return true;
  return url.protocol === 'http:' && allowHttp && isLocalHost(url.hostname) && url.host === api.host;
}
