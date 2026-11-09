/**
 * The ready-made status messages. Tapping one fills the note, which stays editable. When the note is sent
 * exactly as the preset reads, only its key goes to the server, which shows it in each reader's language (the
 * scan page defaults to Turkish), so it matches the server's Car::STATUS_PRESETS.
 */
export const STATUS_PRESETS = [
  { key: 'back_soon', label: 'status.p1' },
  { key: 'loading', label: 'status.p2' },
  { key: 'at_pharmacy', label: 'status.p3' },
  { key: 'call_me', label: 'status.p4' },
] as const;

export type StatusPresetKey = (typeof STATUS_PRESETS)[number]['key'];

/** The preset whose wording (in the app's current language) is exactly `text`, if any. */
export function presetFor(text: string, translate: (key: string) => string): StatusPresetKey | undefined {
  const trimmed = text.trim();
  return STATUS_PRESETS.find((preset) => translate(preset.label) === trimmed)?.key;
}
