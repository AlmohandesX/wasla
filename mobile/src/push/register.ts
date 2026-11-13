import Constants from 'expo-constants';
import { Platform } from 'react-native';

import { registerDeviceToken } from '../api/push';
import { alreadyRegistered, rememberToken } from './deviceToken';

/** Notification responses already acted on, so a remount does not reopen an old thread. */
const handled = new Set<string>();

export type PushState = 'granted' | 'denied' | 'undetermined' | 'unavailable';

// expo-notifications is loaded on demand and every call is guarded: in Expo Go on Android (SDK 53 and later)
// remote push is not available and the module can throw. The app must carry on without push.
async function notifications() {
  return await import('expo-notifications');
}

function projectId(): string | null {
  const extra = Constants.expoConfig?.extra as { eas?: { projectId?: string } } | undefined;
  return extra?.eas?.projectId ?? Constants.easConfig?.projectId ?? null;
}

export async function getPushState(): Promise<PushState> {
  if (!projectId()) return 'unavailable'; // Nothing to register without an EAS project, so nothing to offer.
  try {
    const N = await notifications();
    const { status, canAskAgain } = await N.getPermissionsAsync();
    if (status === 'granted') return 'granted';
    return status === 'denied' && !canAskAgain ? 'denied' : 'undetermined';
  } catch {
    return 'unavailable';
  }
}

/**
 * Gets the device's Expo push token and registers it with the backend. With `ask` false it never shows the
 * permission prompt, so it can run quietly after sign-in; the chats screen calls it with `ask` true when the
 * owner taps to turn notifications on. Never throws.
 */
export async function enablePush(ask: boolean, userId: number): Promise<PushState> {
  try {
    const N = await notifications();
    N.setNotificationHandler({
      handleNotification: async () => ({ shouldShowBanner: true, shouldShowList: true, shouldPlaySound: true, shouldSetBadge: false }),
    });
    if (Platform.OS === 'android') {
      await N.setNotificationChannelAsync('default', { name: 'default', importance: N.AndroidImportance.DEFAULT });
    }

    let { status } = await N.getPermissionsAsync();
    if (status !== 'granted' && ask) status = (await N.requestPermissionsAsync()).status;
    if (status !== 'granted') return status === 'denied' ? 'denied' : 'undetermined';

    const id = projectId();
    if (!id) return 'unavailable'; // No EAS project configured: nothing to register.
    const { data: token } = await N.getExpoPushTokenAsync({ projectId: id });
    if (!alreadyRegistered(userId, token)) {
      await registerDeviceToken(token, Platform.OS === 'ios' || Platform.OS === 'android' ? Platform.OS : null);
      await rememberToken(userId, token);
    }
    return 'granted';
  } catch {
    return 'unavailable';
  }
}

/** The thread id in a chat notification's payload, or null for any other notification. */
export function threadIdFrom(data: unknown): number | null {
  const value = (data as { thread_id?: unknown } | null | undefined)?.thread_id;
  const id = Number(value);
  return Number.isInteger(id) && id > 0 ? id : null;
}

/** Calls `onThread` when the owner taps a chat notification (also one that launched the app). Returns the unsubscribe. */
export function listenForChatTaps(onThread: (threadId: number) => void): () => void {
  let remove: (() => void) | undefined;
  let stopped = false;
  void (async () => {
    try {
      const N = await notifications();
      if (stopped) return;
      const sub = N.addNotificationResponseReceivedListener((response) => {
        handled.add(response.notification.request.identifier);
        const id = threadIdFrom(response.notification.request.content.data);
        if (id) onThread(id);
      });
      remove = () => sub.remove();
      const last = await N.getLastNotificationResponseAsync();
      const responseId = last?.notification.request.identifier;
      const id = threadIdFrom(last?.notification.request.content.data);
      if (id && responseId && !handled.has(responseId) && !stopped) {
        handled.add(responseId);
        onThread(id);
      }
    } catch {
      // No push here (Expo Go): nothing to listen to.
    }
  })();
  return () => {
    stopped = true;
    remove?.();
  };
}
