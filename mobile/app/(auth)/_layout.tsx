import { Stack } from 'expo-router';

import { accessFor } from '../../src/auth/guard';
import { useSession } from '../../src/auth/session';

export default function AuthLayout() {
  const access = accessFor(useSession());
  const signedOut = access === 'auth';
  const needsVerification = access === 'verify';

  return (
    <Stack screenOptions={{ headerShown: false }}>
      <Stack.Protected guard={signedOut}>
        <Stack.Screen name="login" />
        <Stack.Screen name="register" />
        <Stack.Screen name="forgot-password" />
      </Stack.Protected>
      <Stack.Protected guard={needsVerification}>
        <Stack.Screen name="verify-email" />
      </Stack.Protected>
    </Stack>
  );
}
