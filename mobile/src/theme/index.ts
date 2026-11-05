// White and royal blue. #4169E1 is 4.8:1 on white, so it carries text and white-on-blue buttons directly.
export const colors = {
  primary: '#4169E1',
  primaryDark: '#2F4FB8',
  primaryTint: '#EEF2FD',
  surface: '#FFFFFF',
  text: '#14213D',
  textMuted: '#4B5563',
  border: '#7C879A',
  danger: '#B3261E',
  dangerTint: '#FDECEA',
} as const;

export const spacing = { xs: 4, sm: 8, md: 16, lg: 24, xl: 32 } as const;

export const radius = 8;

/** Minimum touch target in dp. */
export const touchTarget = 48;

export const fontSize = { small: 14, body: 16, title: 24 } as const;
