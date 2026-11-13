import type * as GoogleSignInModule from '@react-native-google-signin/google-signin';

import { ApiError } from '../api/errors';
import { t } from '../i18n';

/**
 * Native Google Sign-In. The module only exists in a development or store build: in Expo Go requiring it throws,
 * so it is loaded lazily and the "Continue with Google" button is simply not shown there.
 */
let native: typeof GoogleSignInModule | null | undefined;
function load(): typeof GoogleSignInModule | null {
  if (native === undefined) {
    try {
      // eslint-disable-next-line @typescript-eslint/no-require-imports
      native = require('@react-native-google-signin/google-signin') as typeof GoogleSignInModule;
    } catch {
      native = null;
    }
  }
  return native;
}

/** The Google OAuth "Web" client ID: Google issues the ID token to it, and the server checks that audience. */
function webClientId(): string | undefined {
  // Must stay a literal property access so Expo can inline it into the bundle.
  return process.env.EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID || undefined;
}

export function isGoogleSignInAvailable(): boolean {
  return !!webClientId() && load() !== null;
}

let configured = false;

/**
 * Shows Google's account picker and returns the ID token, or null when the person closes it.
 * Throws an ApiError (code google_unavailable) when Google Play services or the sign-in itself fails.
 */
export async function getGoogleIdToken(): Promise<string | null> {
  const mod = load();
  const clientId = webClientId();
  if (!mod || !clientId) throw unavailable();

  const { GoogleSignin, isErrorWithCode, isSuccessResponse, statusCodes } = mod;
  if (!configured) {
    GoogleSignin.configure({ webClientId: clientId });
    configured = true;
  }

  try {
    await GoogleSignin.hasPlayServices({ showPlayServicesUpdateDialog: true });
    // Forget the previous choice so the picker always shows: on a shared phone, nobody is signed in by accident.
    await GoogleSignin.signOut().catch(() => undefined);
    const response = await GoogleSignin.signIn();
    if (!isSuccessResponse(response)) return null;
    if (!response.data.idToken) throw unavailable();
    return response.data.idToken;
  } catch (e) {
    if (e instanceof ApiError) throw e;
    if (isErrorWithCode(e) && (e.code === statusCodes.SIGN_IN_CANCELLED || e.code === statusCodes.IN_PROGRESS)) return null;
    throw unavailable();
  }
}

/** Forgets the Google account on this phone, so the next sign-in asks again. Never throws. */
export async function signOutOfGoogle(): Promise<void> {
  const mod = load();
  if (!mod || !configured) return;
  await mod.GoogleSignin.signOut().catch(() => undefined);
}

function unavailable(): ApiError {
  return new ApiError(0, 'google_unavailable', t('errors.google_unavailable'));
}
