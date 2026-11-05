import { apiRequest } from './client';
import type { Message } from '../lib/mergeMessages';

export type { Message };

export type Thread = {
  id: number;
  car_id: number;
  blocked: boolean;
  unread_count: number;
  last_message: Message | null;
  updated_at: string;
};

export async function listThreads(): Promise<Thread[]> {
  return (await apiRequest<{ data: Thread[] }>('chats')).data;
}

/** Messages with an id above `after` (0 for all). The server marks the visitor messages it returns as read. */
export async function listMessages(threadId: number, after = 0): Promise<Message[]> {
  const query = after > 0 ? `?after=${after}` : '';
  return (await apiRequest<{ data: Message[] }>(`chats/${threadId}/messages${query}`)).data;
}

export function sendMessage(threadId: number, body: string): Promise<Message> {
  return apiRequest<Message>(`chats/${threadId}/messages`, { method: 'POST', body: { body } });
}

export function blockThread(threadId: number): Promise<Thread> {
  return apiRequest<Thread>(`chats/${threadId}/block`, { method: 'POST' });
}

export function reportMessage(messageId: number): Promise<Message> {
  return apiRequest<Message>(`messages/${messageId}/report`, { method: 'POST' });
}
