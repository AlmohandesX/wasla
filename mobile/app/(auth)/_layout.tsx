import { Stack } from 'expo-router';

import { useSession } from '../../src/auth/session';

export default function AuthLayout() {
  const { user } = useSession();
  const signedOut = !user;
  const needsVerification = !!user && !user.email_verified;

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
