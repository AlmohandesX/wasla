import { apiRequest } from './client';
import { getApiBaseUrl } from './config';

export type CarStatus = { text: string; expires_at: string };

export type Car = {
  id: number;
  model: string;
  plate: string;
  default_message: string | null;
  current_message: string | null;
  status: CarStatus | null;
  stealth_enabled: boolean;
  chats_paused: boolean;
  /** False when no tag is attached (a car whose tag was released, awaiting a new one). */
  linked: boolean;
  subscription: { active: boolean; expires_at: string | null };
};

export type Contact = { id: number; label: string; phone: string; position: number };

export async function listCars(): Promise<Car[]> {
  return (await apiRequest<{ data: Car[] }>('cars')).data;
}

export function getCar(id: number): Promise<Car> {
  return apiRequest<Car>(`cars/${id}`);
}

/**
 * Links a scanned tag. A new car needs model and plate; pass `carId` instead to link a car that has no tag.
 * Claiming a tag that is already on one of your own cars answers with that car (not an error).
 */
export function claimTag(input: { token: string; model?: string; plate?: string; carId?: number }): Promise<Car> {
  const body: Record<string, unknown> = { token: input.token };
  if (input.carId !== undefined) body.car_id = input.carId;
  else {
    body.model = input.model;
    body.plate = input.plate;
  }
  return apiRequest<Car>('tags/claim', { method: 'POST', body });
}

export function updateCar(
  id: number,
  changes: { model?: string; plate?: string; default_message?: string | null },
): Promise<Car> {
  return apiRequest<Car>(`cars/${id}`, { method: 'PATCH', body: changes });
}

export function setStealth(id: number, enabled: boolean): Promise<Car> {
  return apiRequest<Car>(`cars/${id}/stealth`, { method: 'PUT', body: { enabled } });
}

export function setChatsPaused(id: number, paused: boolean): Promise<Car> {
  return apiRequest<Car>(`cars/${id}/chats-paused`, { method: 'PUT', body: { paused } });
}

export const MAX_STATUS_LENGTH = 140;

export function setStatus(id: number, text: string, durationMinutes: number): Promise<Car> {
  return apiRequest<Car>(`cars/${id}/status`, { method: 'PUT', body: { text, duration_minutes: durationMinutes } });
}

export function clearStatus(id: number): Promise<Car> {
  return apiRequest<Car>(`cars/${id}/status`, { method: 'DELETE' });
}

/** Releases the tag from the car, so whoever claims it next gets it. The car stays in the account. */
export function unlinkTag(id: number): Promise<Car> {
  return apiRequest<Car>(`cars/${id}/unlink`, { method: 'POST' });
}

/**
 * Removes the car with its photo, emergency contacts, reminders and chat history. A linked sticker is released with
 * its expiry kept, so anyone can claim it next. Refused (402) while the car's linked sticker is expired.
 */
export async function deleteCar(id: number): Promise<void> {
  await apiRequest<void>(`cars/${id}`, { method: 'DELETE' });
}

export async function listContacts(carId: number): Promise<Contact[]> {
  return (await apiRequest<{ data: Contact[] }>(`cars/${carId}/contacts`)).data;
}

export function addContact(carId: number, input: { label: string; phone: string }): Promise<Contact> {
  return apiRequest<Contact>(`cars/${carId}/contacts`, { method: 'POST', body: input });
}

export async function deleteContact(contactId: number): Promise<void> {
  await apiRequest<void>(`contacts/${contactId}`, { method: 'DELETE' });
}

/** The backend takes JPEG, PNG or WebP up to 10 MB; pickPhoto re-encodes to JPEG at most 1600 px wide, far below that. */
export function uploadPhoto(carId: number, file: { uri: string; name: string; type: string }): Promise<{ has_photo: boolean }> {
  const form = new FormData();
  // React Native's FormData takes a { uri, name, type } descriptor for a file.
  form.append('photo', file as unknown as Blob);
  return apiRequest<{ has_photo: boolean }>(`cars/${carId}/photo`, { method: 'POST', body: form, timeoutMs: 60000 });
}

export async function deletePhoto(carId: number): Promise<void> {
  await apiRequest<void>(`cars/${carId}/photo`, { method: 'DELETE' });
}

/** The photo is served to the owner only, so an Image must send the bearer token along with this address. */
export function carPhotoUrl(carId: number): string {
  return `${getApiBaseUrl()}/cars/${carId}/photo`;
}
