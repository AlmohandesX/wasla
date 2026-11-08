import { Redirect } from 'expo-router';

import { accessFor } from '../src/auth/guard';
import { useSession } from '../src/auth/session';

/**
 * The app's entry route ("/"). It sits outside the protected groups so it always matches, then sends the owner
 * to the right first screen: the car list when signed in and verified, email verification when the address is
 * not confirmed yet, and sign in otherwise. The root layout shows the loading and error states before this runs.
 */
export default function Index() {
  const access = accessFor(useSession());

  if (access === 'app') return <Redirect href="/cars" />;
  if (access === 'verify') return <Redirect href="/verify-email" />;
  return <Redirect href="/login" />;
}
