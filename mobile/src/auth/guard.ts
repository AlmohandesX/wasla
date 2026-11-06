import type { Session } from './session';

/**
 * Which part of the app the owner may be in. The route layouts turn this into Expo Router protected routes,
 * so a signed-out or unverified user cannot reach an owner screen.
 */
export type Access = 'loading' | 'error' | 'auth' | 'verify' | 'app';

export function accessFor(session: Pick<Session, 'user' | 'ready' | 'bootError'>): Access {
  if (!session.ready) return 'loading';
  if (session.bootError) return 'error';
  if (!session.user) return 'auth';
  return session.user.email_verified ? 'app' : 'verify';
}
