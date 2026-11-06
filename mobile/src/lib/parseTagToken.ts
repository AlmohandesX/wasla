import { allowLocalHttp, isLocalHost } from '../api/config';

/** The backend's canonical token shape (TokenGenerator::isWellFormed): 16 base62 characters. */
const TOKEN = /^[0-9A-Za-z]{16}$/;

function parse(raw: string): URL | null {
  try {
    return new URL(raw.trim());
  } catch {
    return null;
  }
}

/**
 * Returns the token when `scanned` is exactly `<tagBaseUrl>/<token>`, otherwise null. Scheme, host (with port) and
 * path are compared as parsed URLs, never as substrings, so a look-alike domain or an embedded URL does not match.
 * One trailing slash is tolerated because the backend route (`t/{token}`) answers with or without it.
 * Plain http counts only in a development build (`allowHttp`) and only for a local address.
 */
export function parseTagToken(scanned: string, tagBaseUrl: string, allowHttp: boolean = allowLocalHttp()): string | null {
  const base = parse(tagBaseUrl);
  const url = parse(scanned);
  if (!base || !url) return null;

  const secure = base.protocol === 'https:';
  if (!secure && !(base.protocol === 'http:' && allowHttp && isLocalHost(base.hostname))) return null;

  if (url.protocol !== base.protocol || url.host !== base.host) return null;
  if (url.username || url.password || url.search || url.hash) return null;

  const prefix = `${base.pathname.replace(/\/+$/, '')}/`;
  if (!url.pathname.startsWith(prefix)) return null;
  const token = url.pathname.slice(prefix.length).replace(/\/$/, '');
  return TOKEN.test(token) ? token : null;
}
