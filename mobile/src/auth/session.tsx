import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { apiRequest, setSessionHandlers } from '../api/client';
import { ApiError, toApiError } from '../api/errors';
import { getLocale, loadLocale } from '../i18n';
import { forgetStoredToken, resetRegistration, unregisterStoredToken } from '../push/deviceToken';
import { clearToken, getToken, setToken } from './token';

export type User = {
  id: number;
  name: string;
  email: string;
  locale: 'tr' | 'en';
  role: string;
  email_verified: boolean;
};

type SessionResponse = { token: string; user: User };

export type Session = {
  /** Null when signed out. */
  user: User | null;
  /** False until the saved token (if any) has been checked. */
  ready: boolean;
  /** Set when the saved session could not be checked because the server was unreachable. */
  bootError: ApiError | null;
  /** Why the owner was signed out without asking to be (shown on the sign-in screen), or null. */
  signedOutReason: 'unauthenticated' | 'account_suspended' | 'account_deleted' | null;
  signIn: (email: string, password: string) => Promise<void>;
  register: (input: { name: string; email: string; password: string; consent: boolean }) => Promise<void>;
  signOut: () => Promise<void>;
  /** Deletes the account (password required), then clears every local trace and ends the session. */
  deleteAccount: (password: string) => Promise<void>;
  /** Re-reads the account (the "I've verified" check). Returns the fresh user. */
  refresh: () => Promise<User>;
  retryBoot: () => void;
};

const SessionContext = createContext<Session | null>(null);

export function SessionProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [ready, setReady] = useState(false);
  const [bootError, setBootError] = useState<ApiError | null>(null);
  const [bootAttempt, setBootAttempt] = useState(0);
  const [signedOutReason, setSignedOutReason] = useState<Session['signedOutReason']>(null);

  // Any session end (sign-out, rejected token, suspension) forgets what was registered for the old account.
  useEffect(() => {
    if (!user) resetRegistration();
  }, [user]);

  useEffect(() => {
    setSessionHandlers({
      onUnauthorized: (reason) => {
        setUser(null);
        setSignedOutReason(reason);
      },
      onEmailNotVerified: () => setUser((u) => (u ? { ...u, email_verified: false } : u)),
    });
    return () => setSessionHandlers({});
  }, []);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      await loadLocale();
      try {
        if (await getToken()) {
          const me = await apiRequest<User>('me');
          if (!cancelled) setUser(me);
        }
        if (!cancelled) setBootError(null);
      } catch (e) {
        // A rejected token or a suspended account has already been signed out (the token is gone), so the
        // sign-in screen shows. Anything else leaves the token alone so a flaky network does not log the owner out.
        if (!cancelled && (await getToken())) setBootError(toApiError(e));
      } finally {
        if (!cancelled) setReady(true);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [bootAttempt]);

  const start = useCallback(async (session: SessionResponse) => {
    await setToken(session.token);
    setSignedOutReason(null);
    setBootError(null);
    setUser(session.user);
  }, []);

  const signIn = useCallback(
    async (email: string, password: string) => {
      const session = await apiRequest<SessionResponse>('auth/login', {
        method: 'POST',
        auth: false,
        body: { email: email.trim(), password },
      });
      await start(session);
    },
    [start],
  );

  const register = useCallback(
    async (input: { name: string; email: string; password: string; consent: boolean }) => {
      const session = await apiRequest<SessionResponse>('auth/register', {
        method: 'POST',
        auth: false,
        body: {
          name: input.name.trim(),
          email: input.email.trim(),
          password: input.password,
          locale: getLocale(),
          privacy_consent: input.consent,
        },
      });
      await start(session);
    },
    [start],
  );

  const signOut = useCallback(async () => {
    try {
      // With no user (the boot error screen) there is nothing to revoke and the server may be unreachable.
      // Otherwise revoke the token and drop this phone's push token together, each with a short timeout:
      // signing out locally must not wait on the server.
      if (user && (await getToken())) {
        await Promise.allSettled([unregisterStoredToken(), apiRequest<void>('auth/logout', { method: 'POST', timeoutMs: 3000 })]);
      }
    } catch {
      // Ignored on purpose.
    }
    await clearToken();
    setSignedOutReason(null);
    setBootError(null);
    setUser(null);
  }, [user]);

  const deleteAccount = useCallback(async (password: string) => {
    await apiRequest<void>('account', { method: 'DELETE', body: { password }, timeoutMs: 30000 });
    await forgetStoredToken();
    await clearToken();
    setBootError(null);
    setSignedOutReason('account_deleted');
    setUser(null);
  }, []);

  const refresh = useCallback(async () => {
    const me = await apiRequest<User>('me');
    setUser(me);
    return me;
  }, []);

  const retryBoot = useCallback(() => {
    setReady(false);
    setBootAttempt((n) => n + 1);
  }, []);

  const value = useMemo<Session>(
    () => ({ user, ready, bootError, signedOutReason, signIn, register, signOut, deleteAccount, refresh, retryBoot }),
    [user, ready, bootError, signedOutReason, signIn, register, signOut, deleteAccount, refresh, retryBoot],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): Session {
  const ctx = useContext(SessionContext);
  if (!ctx) throw new Error('useSession must be used inside SessionProvider');
  return ctx;
}
