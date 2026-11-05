import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

import { apiRequest, setSessionHandlers } from '../api/client';
import { ApiError } from '../api/errors';
import { getLocale, loadLocale } from '../i18n';
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
  signIn: (email: string, password: string) => Promise<void>;
  register: (input: { name: string; email: string; password: string; consent: boolean }) => Promise<void>;
  signOut: () => Promise<void>;
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

  useEffect(() => {
    setSessionHandlers({
      onUnauthorized: () => setUser(null),
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
        // A 401 has already signed out. Anything else leaves the token alone so a flaky network
        // does not log the owner out.
        if (!cancelled && e instanceof ApiError && e.code !== 'unauthenticated') setBootError(e);
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
      // Revoke the token on the server when we can; signing out locally must not depend on it.
      if (await getToken()) await apiRequest<void>('auth/logout', { method: 'POST', timeoutMs: 5000 });
    } catch {
      // Ignored on purpose.
    }
    await clearToken();
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
    () => ({ user, ready, bootError, signIn, register, signOut, refresh, retryBoot }),
    [user, ready, bootError, signIn, register, signOut, refresh, retryBoot],
  );

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>;
}

export function useSession(): Session {
  const ctx = useContext(SessionContext);
  if (!ctx) throw new Error('useSession must be used inside SessionProvider');
  return ctx;
}
