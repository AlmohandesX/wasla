import { apiRequest } from './client';

export const REMINDER_TYPES = ['oil_change', 'license_renewal'] as const;
export type ReminderType = (typeof REMINDER_TYPES)[number];

/** `due_on` is a calendar date, "YYYY-MM-DD". */
export type Reminder = { id: number; car_id: number; type: ReminderType; due_on: string };

export async function listReminders(carId: number): Promise<Reminder[]> {
  return (await apiRequest<{ data: Reminder[] }>(`cars/${carId}/reminders`)).data;
}

export function addReminder(carId: number, input: { type: ReminderType; due_on: string }): Promise<Reminder> {
  return apiRequest<Reminder>(`cars/${carId}/reminders`, { method: 'POST', body: input });
}

export async function deleteReminder(reminderId: number): Promise<void> {
  await apiRequest<void>(`reminders/${reminderId}`, { method: 'DELETE' });
}
