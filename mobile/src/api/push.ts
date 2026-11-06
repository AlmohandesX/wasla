import { apiRequest } from './client';

export async function registerDeviceToken(token: string, platform: 'ios' | 'android' | null): Promise<void> {
  await apiRequest<void>('device-tokens', { method: 'POST', body: platform ? { token, platform } : { token } });
}
