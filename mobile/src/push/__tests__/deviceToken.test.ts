import { alreadyRegistered, rememberToken, resetRegistration } from '../deviceToken';

jest.mock('expo-secure-store', () => ({
  getItemAsync: jest.fn(async () => null),
  setItemAsync: jest.fn(async () => undefined),
  deleteItemAsync: jest.fn(async () => undefined),
}));

test('a token registered for one user is not skipped for the next user on the same phone', async () => {
  await rememberToken(1, 'ExponentPushToken[abc]');
  expect(alreadyRegistered(1, 'ExponentPushToken[abc]')).toBe(true);
  expect(alreadyRegistered(2, 'ExponentPushToken[abc]')).toBe(false);
});

test('any session end resets the registration so the next sign-in registers again', async () => {
  await rememberToken(1, 'ExponentPushToken[abc]');
  resetRegistration();
  expect(alreadyRegistered(1, 'ExponentPushToken[abc]')).toBe(false);
});
